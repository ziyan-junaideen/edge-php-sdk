<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\Customer;
use Edge\Exception;
use Edge\Merchant;
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

class MerchantTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'MerchantTest/1.0',
        ]);
    }

    /** @dataProvider readOperations */
    public function testReadsUseMerchantRoutesQueriesHeadersAndEmptyBodies($operation, $useResource)
    {
        $raw = ['type' => 'merchants', 'id' => 'a/b ?#%'];
        $client = $this->client([new Response(200, [], json_encode(['data' => $operation === 'list' ? [$raw] : $raw]))]);
        $query = [
            'include' => ['customers', 'payment_methods'], 'sort' => ['-created_at', 'business_name'],
            'fields' => ['merchants' => ['business_name', 'entity_type', 'customers'], 'customers' => 'name,email'],
            'filter' => ['customers' => ['email' => 'ada@example.com']], 'page' => ['number' => 2, 'size' => 25],
        ];
        $result = $operation === 'list' ? Merchant::list($client, $query)
            : Merchant::show($client, $useResource ? new Merchant($raw) : $raw['id'], $query);
        $request = $this->history[0]['request'];
        $expectedQuery = $query;
        $expectedQuery['include'] = 'customers,payment_methods';
        $expectedQuery['sort'] = '-created_at,business_name';
        $expectedQuery['fields']['merchants'] = 'business_name,entity_type,customers';
        parse_str($request->getUri()->getQuery(), $sent);
        $this->assertEquals($expectedQuery, $sent);
        $this->assertSame($expectedQuery, $result->query);
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api.tryedge.io', $request->getUri()->getHost());
        $this->assertSame('/v2/merchants' . ($operation === 'list' ? '' : '/a%2Fb%20%3F%23%25'), $request->getUri()->getPath());
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
        $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('MerchantTest/1.0', $request->getHeaderLine('User-Agent'));
        $this->assertTrue($this->history[0]['options']['verify']);
        $this->assertInstanceOf(ResourceResult::class, $result);
        $this->assertInstanceOf(Merchant::class, $operation === 'list' ? $result->data[0] : $result->data);
        $this->assertSame(200, $result->status);
    }

    public function readOperations()
    {
        return [['list', false], ['show', false], ['show', true]];
    }

    public function testBusinessFieldsAndExposedRelationshipsDecodeWithoutFetching()
    {
        $attributes = [
            'business_name' => 'Example Business', 'business_description' => null,
            'phone_number' => '+15555550100', 'business_email' => 'business@example.com',
            'prior_bankruptcies' => 'false', 'category_code' => '0123',
            'business_privacy_policy_url' => 'https://example.com/privacy',
            'business_support_email' => 'support@example.com', 'business_support_url' => 'https://example.com/support',
            'business_terms_url' => 'https://example.com/terms', 'business_timezone' => 'Asia/Colombo',
            'business_website' => 'https://example.com', 'business_zip_code' => '00100', 'entity_type' => 'future_entity',
            'active_at' => '2026-09-13T09:00:00Z', 'created_at' => '2026-09-13T10:00:00.123456Z',
            'updated_at' => '2026-09-13T12:00:00+02:00', 'future_field' => ['kept' => true],
            'amount_cents' => 100, 'amount_currency' => 'USD', 'future_at' => '2026-09-13T09:00:00Z',
        ];
        $relationships = [];
        foreach (['customers', 'payment_demands', 'payment_methods', 'beneficial_owners', 'corporate_officials'] as $type) {
            $relationships[$type] = ['data' => [['type' => $type, 'id' => '1']]];
        }
        $relationships['customers']['data'][] = ['type' => 'customers', 'id' => 'missing'];
        $raw = [
            'type' => 'merchants', 'id' => 'merchant-1', 'attributes' => $attributes,
            'relationships' => $relationships, 'links' => (object) [], 'meta' => ['source' => 'test'], 'extension' => true,
        ];
        $included = [];
        foreach (['customers', 'payment_demands', 'payment_methods', 'beneficial_owners', 'corporate_officials'] as $type) {
            $included[] = ['type' => $type, 'id' => '1', 'relationships' => [
                'merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']],
            ]];
        }
        $document = ['data' => $raw, 'included' => $included,
            'links' => ['self' => '/v2/merchants/merchant-1'], 'meta' => ['example' => true]];
        $client = $this->client([new Response(200, ['X-Request-Id' => 'merchant-request'], json_encode($document))]);
        $result = Merchant::show($client, 'merchant-1', ['include' => implode(',', array_keys($relationships))]);
        $merchant = $result->data;
        foreach (array_diff(array_keys($attributes), ['active_at', 'created_at', 'updated_at', 'future_field']) as $field) {
            $this->assertSame($attributes[$field], $merchant->$field);
        }
        $this->assertTrue($merchant->future_field->kept);
        $this->assertSame('123456', $merchant->created_at->format('u'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $merchant->active_at);
        $this->assertInstanceOf(\DateTimeImmutable::class, $merchant->updated_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $merchant->amount);
        $classes = [Customer::class, PaymentDemand::class, \Edge\PaymentMethod::class, \Edge\Resource::class, \Edge\Resource::class];
        foreach (array_keys($relationships) as $i => $name) {
            $related = $merchant->getRelated($name)[0];
            $this->assertSame($classes[$i], get_class($related));
            $this->assertSame($result->included[$i], $related);
            $this->assertSame($merchant, $related->getRelated('merchant'));
        }
        $this->assertInstanceOf(\Edge\Linkage::class, $merchant->getRelated('customers')[1]);
        $this->assertSame('missing', $merchant->getRelated('customers')[1]->id);
        $this->assertEquals(json_decode(json_encode($raw)), $merchant->getRaw());
        $this->assertEquals(json_decode(json_encode($document)), $result->raw);
        $this->assertEquals((object) $document['links'], $result->links);
        $this->assertTrue($result->meta->example);
        $this->assertSame(['merchant-request'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $this->history);
        $this->expectException(\LogicException::class);
        $merchant->business_name = 'Changed';
    }

    public function testIncludedMerchantsUseDefaultSchemaSparseFieldsAndLocalOverrides()
    {
        $response = new \Edge\Response(new Response(200, [], json_encode([
            'data' => ['type' => 'future_records', 'id' => '1', 'relationships' => [
                'merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']],
            ]],
            'included' => [['type' => 'merchants', 'id' => 'merchant-1', 'attributes' => [
                'entity_type' => 'future_entity_type', 'active_at' => '2026-09-13T10:00:00Z',
            ]]],
        ])));
        $result = (new ResourceDecoder())->decode($response, ['fields' => ['merchants' => 'entity_type,active_at']]);
        $merchant = $result->data->getRelated('merchant');
        $this->assertInstanceOf(Merchant::class, $merchant);
        $this->assertSame($result->included[0], $merchant);
        $this->assertSame('future_entity_type', $merchant->entity_type);
        $this->assertInstanceOf(\DateTimeImmutable::class, $merchant->active_at);
        foreach (array_diff(Merchant::FIELDS, ['entity_type', 'active_at']) as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $merchant->$field);
        }
        foreach (['consumer_addresses', 'events', 'webhook_subscriptions', 'payment_subscriptions'] as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $merchant->$field);
        }
        $custom = (new ResourceDecoder())->register('merchants', DecodedResource::class)->decode($response);
        $this->assertInstanceOf(DecodedResource::class, $custom->included[0]);
        $this->assertInstanceOf(Merchant::class, (new ResourceResult($response))->included[0]);
    }

    public function testSparseDatesNullRelationshipsAndExplicitPagination()
    {
        $client = $this->client([
            new Response(200, [], '{"data":{"type":"merchants","id":"1","attributes":{"created_at":"invalid","updated_at":null,"active_at":"2026-02-30T10:00:00Z"},"relationships":{"customers":{"data":null},"payment_demands":{"data":[]}}}}'),
            new Response(200, [], '{"data":[],"links":{"next":"https://api.tryedge.io/v2/merchants?page[number]=2"}}'),
            new Response(204),
        ]);
        $merchant = Merchant::show($client, '1', ['fields' => ['merchants' => ['created_at', 'updated_at', 'active_at', 'business_name', 'customers', 'payment_demands']]])->data;
        $this->assertSame(Unavailable::DECODING_FAILURE, $merchant->created_at->reason);
        $this->assertSame(Unavailable::DECODING_FAILURE, $merchant->active_at->reason);
        $this->assertNull($merchant->updated_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $merchant->business_name);
        $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $merchant->entity_type);
        $this->assertNull($merchant->getRelated('customers'));
        $this->assertSame([], $merchant->getRelated('payment_demands'));
        $page = Merchant::list($client);
        $this->assertSame([], $page->data);
        $this->assertSame('https://api.tryedge.io/v2/merchants?page[number]=2', $page->links->next);
        $this->assertCount(2, $this->history);
        $this->assertNull(Merchant::show($client, '1')->data);
    }

    public function testMismatchedResourcesAreRejectedAndWriteOperationsAreAbsent()
    {
        $client = $this->client([]);
        foreach ([new Customer(['type' => 'merchants', 'id' => '1']), new Merchant(['type' => 'customers', 'id' => '1'])] as $resource) {
            try {
                Merchant::show($client, $resource);
                $this->fail('Expected mismatched resource rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $this->history);
            }
        }
        foreach (['create', 'update', 'delete', 'confirm'] as $operation) {
            $this->assertFalse(method_exists(Merchant::class, $operation));
        }
    }

    /** @dataProvider failedReads */
    public function testApiFailuresRetainResponseDetails($operation, $status, $body)
    {
        $client = $this->client([new Response($status, [], $body)]);
        try {
            $operation === 'list' ? Merchant::list($client) : Merchant::show($client, 'merchant-1');
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
        $failure = new ConnectException('Connection failed', new Request('GET', 'https://api.tryedge.io/v2/merchants'));
        $client = $this->client([$failure]);
        try {
            Merchant::list($client);
            $this->fail('Expected network failure.');
        } catch (Exception $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertSame($failure, $e->getPrevious());
        }
    }
}
