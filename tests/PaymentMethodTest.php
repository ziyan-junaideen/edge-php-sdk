<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\ConsumerAddress;
use Edge\Customer;
use Edge\Exception;
use Edge\Linkage;
use Edge\PaymentMethod;
use Edge\PaymentDemand;
use Edge\ResourceDecoder;
use Edge\ResourceResult;
use Edge\Unavailable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class PaymentMethodTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'PaymentMethodTest/1.0',
        ]);
    }

    /** @dataProvider readOperations */
    public function testReadsUsePaymentMethodRoutesQueriesHeadersAndEmptyBodies($operation, $useResource)
    {
        $raw = ['type' => 'payment_methods', 'id' => 'a/b ?#%'];
        $client = $this->client([new Response(200, [], json_encode(['data' => $operation === 'list' ? [$raw] : $raw]))]);
        $query = [
            'include' => ['customer', 'address'], 'sort' => ['-created_at', 'nickname'],
            'fields' => ['payment_methods' => ['nickname', 'kind', 'customer'], 'customers' => 'name,email'],
            'filter' => ['customer' => ['email' => 'ada@example.com']], 'page' => ['number' => 2, 'size' => 25],
        ];
        $result = $operation === 'list' ? PaymentMethod::list($client, $query)
            : PaymentMethod::show($client, $useResource ? new PaymentMethod($raw) : $raw['id'], $query);
        $request = $this->history[0]['request'];
        $expectedQuery = $query;
        $expectedQuery['include'] = 'customer,address';
        $expectedQuery['sort'] = '-created_at,nickname';
        $expectedQuery['fields']['payment_methods'] = 'nickname,kind,customer';
        parse_str($request->getUri()->getQuery(), $sent);
        $this->assertEquals($expectedQuery, $sent);
        $this->assertSame($expectedQuery, $result->query);
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api.tryedge.io', $request->getUri()->getHost());
        $this->assertSame('/v2/payment_methods' . ($operation === 'list' ? '' : '/a%2Fb%20%3F%23%25'), $request->getUri()->getPath());
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
        $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('PaymentMethodTest/1.0', $request->getHeaderLine('User-Agent'));
        $this->assertTrue($this->history[0]['options']['verify']);
        $this->assertInstanceOf(ResourceResult::class, $result);
        $this->assertInstanceOf(PaymentMethod::class, $operation === 'list' ? $result->data[0] : $result->data);
        $this->assertSame(200, $result->status);
    }

    public function readOperations()
    {
        return [['list', false], ['show', false], ['show', true]];
    }

    public function testValuesUnknownKindsAndRelationshipsDecodeWithoutFetching()
    {
        $attributes = [
            'nickname' => 'Test card', 'card_bin' => '000000', 'card_cvv_token' => 'fake-cvv-token',
            'last_four' => '0001', 'card_pan_token' => 'fake-pan-token', 'description' => null,
            'external_state' => 'future_state', 'kind' => 'future_kind', 'expiry_month' => 9, 'expiry_year' => 2030,
            'created_at' => '2026-09-13T10:00:00.123456Z', 'updated_at' => '2026-09-13T12:00:00+02:00',
            'discarded_at' => null, 'future_field' => ['kept' => true],
            'amount_cents' => 100, 'amount_currency' => 'USD',
        ];
        $raw = [
            'type' => 'payment_methods', 'id' => 'method-1', 'attributes' => $attributes,
            'relationships' => [
                'customer' => ['data' => ['type' => 'customers', 'id' => 'customer-1']],
                'address' => ['data' => ['type' => 'consumer_addresses', 'id' => 'address-1']],
                'merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']],
                'payment_demands' => ['data' => [['type' => 'payment_demands', 'id' => 'demand-1']]],
            ],
            'links' => (object) [], 'meta' => ['source' => 'test'], 'extension' => true,
        ];
        $document = [
            'data' => $raw,
            'included' => [
                ['type' => 'customers', 'id' => 'customer-1'],
                ['type' => 'consumer_addresses', 'id' => 'address-1'],
                ['type' => 'payment_demands', 'id' => 'demand-1', 'relationships' => [
                    'payment_method' => ['data' => ['type' => 'payment_methods', 'id' => 'method-1']],
                ]],
            ],
            'links' => ['self' => '/v2/payment_methods/method-1'], 'meta' => ['example' => true],
        ];
        $client = $this->client([new Response(200, ['X-Request-Id' => 'method-request'], json_encode($document))]);
        $result = PaymentMethod::show($client, 'method-1', ['include' => 'customer,address,payment_demands']);
        $method = $result->data;
        foreach (array_diff(array_keys($attributes), ['created_at', 'updated_at', 'future_field']) as $field) {
            $this->assertSame($attributes[$field], $method->$field);
        }
        $this->assertTrue($method->future_field->kept);
        $this->assertSame('123456', $method->created_at->format('u'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $method->updated_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $method->amount);
        $this->assertInstanceOf(Customer::class, $method->getRelated('customer'));
        $this->assertInstanceOf(ConsumerAddress::class, $method->getRelated('address'));
        $this->assertInstanceOf(Linkage::class, $method->getRelated('merchant'));
        $demand = $method->getRelated('payment_demands')[0];
        $this->assertSame(PaymentDemand::class, get_class($demand));
        $this->assertSame($method, $demand->getRelated('payment_method'));
        $this->assertEquals(json_decode(json_encode($raw)), $method->getRaw());
        $this->assertEquals(json_decode(json_encode($document)), $result->raw);
        $this->assertEquals((object) $document['links'], $result->links);
        $this->assertTrue($result->meta->example);
        $this->assertSame(['method-request'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $this->history);
        $this->expectException(\LogicException::class);
        $method->nickname = 'Changed';
    }

    public function testIncludedMethodsUseDefaultSchemaSparseFieldsAndLocalOverrides()
    {
        $response = new \Edge\Response(new Response(200, [], json_encode([
            'data' => ['type' => 'future_records', 'id' => '1', 'relationships' => [
                'payment_method' => ['data' => ['type' => 'payment_methods', 'id' => 'method-1']],
            ]],
            'included' => [['type' => 'payment_methods', 'id' => 'method-1', 'attributes' => [
                'kind' => 'future_kind', 'discarded_at' => '2026-09-13T10:00:00Z',
            ]]],
        ])));
        $result = (new ResourceDecoder())->decode($response, ['fields' => ['payment_methods' => 'kind,discarded_at']]);
        $method = $result->data->getRelated('payment_method');
        $this->assertInstanceOf(PaymentMethod::class, $method);
        $this->assertSame($result->included[0], $method);
        $this->assertSame('future_kind', $method->kind);
        $this->assertInstanceOf(\DateTimeImmutable::class, $method->discarded_at);
        foreach (array_diff(PaymentMethod::FIELDS, ['kind', 'discarded_at']) as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $method->$field);
        }
        $custom = (new ResourceDecoder())->register('payment_methods', DecodedResource::class)->decode($response);
        $this->assertInstanceOf(DecodedResource::class, $custom->included[0]);
        $this->assertInstanceOf(PaymentMethod::class, (new ResourceResult($response))->included[0]);
    }

    public function testSparseDatesNullRelationshipsAndExplicitPagination()
    {
        $client = $this->client([
            new Response(200, [], '{"data":{"type":"payment_methods","id":"1","attributes":{"created_at":"invalid","updated_at":null,"discarded_at":"2026-02-30T10:00:00Z"},"relationships":{"address":{"data":null},"payment_demands":{"data":[]}}}}'),
            new Response(200, [], '{"data":[],"links":{"next":"https://api.tryedge.io/v2/payment_methods?page[number]=2"}}'),
            new Response(204),
        ]);
        $method = PaymentMethod::show($client, '1', ['fields' => ['payment_methods' => ['created_at', 'updated_at', 'discarded_at', 'nickname', 'address', 'payment_demands']]])->data;
        $this->assertSame(Unavailable::DECODING_FAILURE, $method->created_at->reason);
        $this->assertSame(Unavailable::DECODING_FAILURE, $method->discarded_at->reason);
        $this->assertNull($method->updated_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $method->nickname);
        $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $method->kind);
        $this->assertNull($method->getRelated('address'));
        $this->assertSame([], $method->getRelated('payment_demands'));
        $page = PaymentMethod::list($client);
        $this->assertSame([], $page->data);
        $this->assertSame('https://api.tryedge.io/v2/payment_methods?page[number]=2', $page->links->next);
        $this->assertCount(2, $this->history);
        $this->assertNull(PaymentMethod::show($client, '1')->data);
    }

    public function testMismatchedResourcesAreRejectedAndWriteOperationsAreAbsent()
    {
        $client = $this->client([]);
        foreach ([new Customer(['type' => 'payment_methods', 'id' => '1']), new PaymentMethod(['type' => 'customers', 'id' => '1'])] as $resource) {
            try {
                PaymentMethod::show($client, $resource);
                $this->fail('Expected mismatched resource rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $this->history);
            }
        }
        foreach (['create', 'update', 'delete', 'confirm'] as $operation) {
            $this->assertFalse(method_exists(PaymentMethod::class, $operation));
        }
    }

    /** @dataProvider failedReads */
    public function testApiFailuresRetainResponseDetails($operation, $status, $body)
    {
        $client = $this->client([new Response($status, [], $body)]);
        try {
            $operation === 'list' ? PaymentMethod::list($client) : PaymentMethod::show($client, 'method-1');
            $this->fail('Expected API failure.');
        } catch (Exception $e) {
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame($body, $e->getRawBody());
            $this->assertCount(1, $this->history);
            if ($status === 403) {
                $this->assertSame('Forbidden', $e->getErrors()[0]['title']);
            }
        }
    }

    public function failedReads()
    {
        return [['list', 401, 'Unauthorized'], ['show', 404, ''], ['show', 403, '{"errors":[{"status":"403","title":"Forbidden"}]}']];
    }

    public function testNetworkFailuresKeepTheUnderlyingException()
    {
        $failure = new ConnectException('Connection failed', new Request('GET', 'https://api.tryedge.io/v2/payment_methods'));
        $client = $this->client([$failure]);
        try {
            PaymentMethod::list($client);
            $this->fail('Expected network failure.');
        } catch (Exception $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertSame($failure, $e->getPrevious());
        }
    }
}
