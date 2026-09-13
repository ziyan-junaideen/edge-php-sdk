<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\Customer;
use Edge\Exception;
use Edge\Linkage;
use Edge\Money;
use Edge\PaymentDemand;
use Edge\RefundDemand;
use Edge\ResourceDecoder;
use Edge\ResourceResult;
use Edge\Unavailable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class RefundDemandTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'RefundTest/1.0',
        ]);
    }

    /** @dataProvider operations */
    public function testOperationsUseExactRoutesQueriesHeadersAndBodies($operation, $useResource)
    {
        $raw = ['type' => 'refund_demands', 'id' => 'a/b ?#%'];
        $status = $operation === 'create' ? 201 : 200;
        $client = $this->client([new Response($status, [], json_encode(['data' => $operation === 'list' ? [$raw] : $raw]))]);
        $query = [
            'include' => ['payment_demand', 'merchant'], 'sort' => ['-created_at', 'state'],
            'fields' => ['refund_demands' => ['state', 'amount_cents', 'amount_currency'], 'payment_demands' => 'description'],
            'filter' => ['payment_demand' => ['id' => 'payment-1']], 'page' => ['size' => 25, 'number' => 2],
        ];
        $attributes = ['amount_cents' => 1000, 'amount_currency' => 'USD', 'reason' => 'custom',
            'reason_note' => 'Partial refund', 'idempotency_key' => 'refund-test-1'];
        if ($operation === 'create') {
            $identity = ['type' => 'payment_demands', 'id' => 'payment-1'];
            $result = RefundDemand::create($client, ['attributes' => $attributes,
                'relationships' => ['payment_demand' => $useResource ? new PaymentDemand($identity) : $identity], 'query' => $query]);
        } elseif ($operation === 'show') {
            $result = RefundDemand::show($client, $useResource ? new RefundDemand($raw) : $raw['id'], $query);
        } else {
            $result = RefundDemand::list($client, $query);
        }
        $request = $this->history[0]['request'];
        $expectedQuery = $query;
        $expectedQuery['include'] = 'payment_demand,merchant';
        $expectedQuery['sort'] = '-created_at,state';
        $expectedQuery['fields']['refund_demands'] = 'state,amount_cents,amount_currency';
        parse_str($request->getUri()->getQuery(), $sent);
        $this->assertEquals($expectedQuery, $sent);
        $this->assertSame($expectedQuery, $result->query);
        $this->assertSame('/v2/refund_demands' . ($operation === 'show' ? '/a%2Fb%20%3F%23%25' : ''), $request->getUri()->getPath());
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api.tryedge.io', $request->getUri()->getHost());
        $this->assertSame($operation === 'create' ? 'POST' : 'GET', $request->getMethod());
        $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
        $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('RefundTest/1.0', $request->getHeaderLine('User-Agent'));
        $this->assertFalse($request->hasHeader('Idempotency-Key'));
        $this->assertTrue($this->history[0]['options']['verify']);
        if ($operation === 'create') {
            $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Content-Type'));
            $this->assertSame(json_encode(['data' => ['type' => 'refund_demands', 'attributes' => $attributes,
                'relationships' => ['payment_demand' => ['data' => $identity]]]]), (string) $request->getBody());
        } else {
            $this->assertSame('', (string) $request->getBody());
        }
        $this->assertInstanceOf(ResourceResult::class, $result);
        $this->assertInstanceOf(RefundDemand::class, $operation === 'list' ? $result->data[0] : $result->data);
        $this->assertSame($status, $result->status);
    }

    public function operations()
    {
        return [['list', false], ['show', false], ['show', true], ['create', false], ['create', true]];
    }

    public function testRemainingBalanceRequestDoesNotInventAmountOrCurrency()
    {
        $client = $this->client([new Response(201)]);
        RefundDemand::create($client, ['relationships' => ['payment_demand' => ['type' => 'payment_demands', 'id' => '1']]]);
        $this->assertSame('{"data":{"type":"refund_demands","attributes":{},"relationships":{"payment_demand":{"data":{"type":"payment_demands","id":"1"}}}}}', (string) $this->history[0]['request']->getBody());
    }

    /** @dataProvider states */
    public function testRefundStatesMoneyDatesAndRelationshipsDecodeWithoutCalculations($state)
    {
        $attributes = ['amount_cents' => 1000, 'amount_currency' => 'USD', 'state' => $state,
            'reason' => 'future_reason', 'reason_note' => null, 'idempotency_key' => 'test-refund',
            'created_at' => '2026-09-13T10:00:00.123456Z', 'updated_at' => '2026-09-13T12:00:00+02:00', 'future' => true];
        $raw = ['type' => 'refund_demands', 'id' => '1', 'attributes' => $attributes, 'relationships' => [
            'payment_demand' => ['data' => ['type' => 'payment_demands', 'id' => '2']],
            'merchant' => ['data' => ['type' => 'merchants', 'id' => '3']],
        ], 'links' => (object) [], 'meta' => ['example' => true], 'extension' => true];
        $document = ['data' => $raw, 'included' => [['type' => 'payment_demands', 'id' => '2']],
            'links' => ['self' => '/v2/refund_demands/1'], 'meta' => ['example' => true]];
        $client = $this->client([new Response(200, ['X-Request-Id' => 'refund-request'], json_encode($document))]);
        $result = RefundDemand::show($client, '1');
        $refund = $result->data;
        $this->assertEquals(new Money(1000, 'USD'), $refund->amount);
        foreach (array_diff(array_keys($attributes), ['created_at', 'updated_at']) as $field) {
            $this->assertSame($attributes[$field], $refund->$field);
        }
        $this->assertSame('123456', $refund->created_at->format('u'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $refund->updated_at);
        $this->assertInstanceOf(PaymentDemand::class, $refund->getRelated('payment_demand'));
        $this->assertSame($result->included[0], $refund->getRelated('payment_demand'));
        $this->assertInstanceOf(Linkage::class, $refund->getRelated('merchant'));
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $refund->fee);
        $this->assertEquals(json_decode(json_encode($raw)), $refund->getRaw());
        $this->assertEquals(json_decode(json_encode($document)), $result->raw);
        $this->assertTrue($result->meta->example);
        $this->assertSame('/v2/refund_demands/1', $result->links->self);
        $this->assertSame(['refund-request'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $this->history);
        $this->expectException(\LogicException::class);
        $refund->state = 'changed';
    }

    public function states()
    {
        return [['failed'], ['succeeded'], ['future_state']];
    }

    public function testIncludedRefundsHaveDefaultMappingsSparseFieldsAndLocalOverrides()
    {
        $response = new \Edge\Response(new Response(200, [], json_encode([
            'data' => ['type' => 'future_records', 'id' => '1', 'relationships' => [
                'refund' => ['data' => ['type' => 'refund_demands', 'id' => '2']],
            ]], 'included' => [['type' => 'refund_demands', 'id' => '2', 'attributes' => [
                'amount_cents' => 1000, 'amount_currency' => 'USD', 'created_at' => '2026-09-13T10:00:00Z',
            ]]],
        ])));
        $selected = ['amount_cents', 'amount_currency', 'created_at'];
        $result = (new ResourceDecoder())->decode($response, ['fields' => ['refund_demands' => $selected]]);
        $refund = $result->data->getRelated('refund');
        $this->assertInstanceOf(RefundDemand::class, $refund);
        $this->assertSame($result->included[0], $refund);
        $this->assertEquals(new Money(1000, 'USD'), $refund->amount);
        $this->assertInstanceOf(\DateTimeImmutable::class, $refund->created_at);
        foreach (array_diff(RefundDemand::FIELDS, $selected) as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $refund->$field);
        }
        $custom = (new ResourceDecoder())->register('refund_demands', DecodedResource::class)->decode($response);
        $this->assertInstanceOf(DecodedResource::class, $custom->included[0]);
        $this->assertInstanceOf(RefundDemand::class, (new ResourceResult($response))->included[0]);
    }

    public function testNullsInvalidValuesSparseFieldsAndPaginationRemainDistinct()
    {
        $client = $this->client([
            new Response(200, [], '{"data":{"type":"refund_demands","id":"1","attributes":{"amount_cents":100,"created_at":"invalid","updated_at":null}}}'),
            new Response(200, [], '{"data":{"type":"refund_demands","id":"1","attributes":{"amount_cents":null,"amount_currency":null}}}'),
            new Response(200, [], '{"data":{"type":"refund_demands","id":"1"}}'),
            new Response(200, [], '{"data":[],"links":{"next":"https://api.tryedge.io/v2/refund_demands?page[number]=2"}}'),
            new Response(204),
        ]);
        $refund = RefundDemand::show($client, '1')->data;
        $this->assertSame(Unavailable::DECODING_FAILURE, $refund->amount->reason);
        $this->assertSame(Unavailable::DECODING_FAILURE, $refund->created_at->reason);
        $this->assertNull($refund->updated_at);
        $this->assertNull(RefundDemand::show($client, '1')->data->amount);
        $sparse = RefundDemand::show($client, '1', ['fields' => ['refund_demands' => 'state']])->data;
        $this->assertSame(Unavailable::UNFETCHED, $sparse->amount->reason);
        $this->assertSame(Unavailable::UNDEFINED, $sparse->state->reason);
        $page = RefundDemand::list($client);
        $this->assertSame([], $page->data);
        $this->assertSame('https://api.tryedge.io/v2/refund_demands?page[number]=2', $page->links->next);
        $this->assertCount(4, $this->history);
        $this->assertNull(RefundDemand::show($client, '1')->data);
    }

    public function testWrongResourceArgumentsAndUnsupportedOperations()
    {
        $client = $this->client([]);
        foreach ([new Customer(['type' => 'refund_demands', 'id' => '1']), new RefundDemand(['type' => 'customers', 'id' => '1'])] as $resource) {
            try {
                RefundDemand::show($client, $resource);
                $this->fail('Expected mismatched resource rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $this->history);
            }
        }
        foreach (['update', 'confirm', 'delete'] as $operation) {
            $this->assertFalse(method_exists(RefundDemand::class, $operation));
        }
    }

    /** @dataProvider failures */
    public function testApiFailuresPreserveResponseDetails($operation, $status, $body)
    {
        $client = $this->client([new Response($status, [], $body)]);
        try {
            $operation === 'show' ? RefundDemand::show($client, '1') : RefundDemand::$operation($client);
            $this->fail('Expected API failure.');
        } catch (Exception $e) {
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame($body, $e->getRawBody());
            if ($status === 422) {
                $this->assertSame('/data/attributes/amount_cents', $e->getErrors()[0]['source']['pointer']);
            }
            $this->assertCount(1, $this->history);
        }
    }

    public function failures()
    {
        return [['list', 401, 'Unauthorized'], ['show', 404, ''],
            ['create', 422, '{"errors":[{"status":"422","detail":"Amount exceeds remaining balance","source":{"pointer":"/data/attributes/amount_cents"}}]}'],
            ['create', 409, '{"errors":[{"status":"409","title":"Idempotency conflict"}]}']];
    }
}
