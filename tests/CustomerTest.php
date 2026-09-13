<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\ConsumerAddress;
use Edge\Customer;
use Edge\Exception;
use Edge\Linkage;
use Edge\Resource;
use Edge\ResourceDecoder;
use Edge\ResourceResult;
use Edge\Unavailable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class CustomerTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'CustomerTest/1.0',
        ]);
    }

    private function response($data, $status = 200, array $extra = [])
    {
        return new Response($status, ['X-Request-Id' => 'customer-request'], json_encode(['data' => $data] + $extra));
    }

    public function testSupportedOperationsUseCustomerRoutesHeadersAndExactWriteBodies()
    {
        $raw = ['type' => 'customers', 'id' => 'a/b ?#%', 'attributes' => ['name' => 'Ada']];
        $client = $this->client([
            $this->response([$raw]), $this->response($raw), $this->response($raw, 201),
            $this->response($raw), $this->response($raw), $this->response($raw),
        ]);
        $customer = new Customer($raw);
        $query = [
            'include' => ['addresses', 'merchant'], 'sort' => ['-created_at', 'name'],
            'fields' => ['customers' => ['name', 'addresses']],
            'filter' => ['email' => ['eq' => 'ada@example.com']], 'page' => ['size' => 25],
        ];
        $attributes = ['name' => 'Ada', 'email' => 'ada@example.com', 'phone_number' => '+12025550123', 'description' => 'New customer'];
        $results = [
            Customer::list($client, $query),
            Customer::show($client, $customer, $query),
            Customer::create($client, ['attributes' => $attributes, 'query' => $query]),
            Customer::update($client, $customer, ['attributes' => ['description' => null], 'query' => $query]),
            Customer::show($client, $customer->id, $query),
            Customer::update($client, $customer->id, ['query' => $query]),
        ];
        $expectedQuery = $query;
        $expectedQuery['include'] = 'addresses,merchant';
        $expectedQuery['sort'] = '-created_at,name';
        $expectedQuery['fields']['customers'] = 'name,addresses';
        foreach (['GET', 'GET', 'POST', 'PATCH', 'GET', 'PATCH'] as $i => $method) {
            $request = $this->history[$i]['request'];
            $this->assertSame($method, $request->getMethod());
            $this->assertSame('https', $request->getUri()->getScheme());
            $this->assertSame('api.tryedge.io', $request->getUri()->getHost());
            $this->assertSame('/v2/customers' . (in_array($i, [0, 2], true) ? '' : '/a%2Fb%20%3F%23%25'), $request->getUri()->getPath());
            $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
            $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
            $this->assertStringContainsString('CustomerTest/1.0', $request->getHeaderLine('User-Agent'));
            $this->assertTrue($this->history[$i]['options']['verify']);
            parse_str($request->getUri()->getQuery(), $sent);
            $this->assertEquals($expectedQuery, $sent);
            $this->assertInstanceOf(ResourceResult::class, $results[$i]);
            $this->assertInstanceOf(Customer::class, $i === 0 ? $results[$i]->data[0] : $results[$i]->data);
            $this->assertSame($i === 2 ? 201 : 200, $results[$i]->status);
            if ($method === 'GET') {
                $this->assertSame('', (string) $request->getBody());
            } else {
                $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Content-Type'));
                $expected = ['type' => 'customers'];
                if ($i !== 2) {
                    $expected['id'] = $customer->id;
                }
                $expected['attributes'] = (object) ($i === 2 ? $attributes : ($i === 3 ? ['description' => null] : []));
                $expected['relationships'] = new \stdClass();
                $this->assertEquals((object) ['data' => (object) $expected], json_decode((string) $request->getBody()));
            }
        }
    }

    public function testAddressLinkageEncodingDoesNotPromiseBackendPersistence()
    {
        $client = $this->client([$this->response(null, 201), $this->response(null)]);
        $address = new Resource(['type' => 'consumer_addresses', 'id' => 'address-1']);
        Customer::create($client, ['relationships' => ['addresses' => [$address]]]);
        Customer::update($client, 'customer-1', ['relationships' => ['addresses' => []]]);
        $this->assertSame('{"data":{"type":"customers","attributes":{},"relationships":{"addresses":{"data":[{"type":"consumer_addresses","id":"address-1"}]}}}}', (string) $this->history[0]['request']->getBody());
        $this->assertSame('{"data":{"type":"customers","id":"customer-1","attributes":{},"relationships":{"addresses":{"data":[]}}}}', (string) $this->history[1]['request']->getBody());
    }

    public function testCustomerDecodingPreservesFieldsRelationshipsAndResponseMetadata()
    {
        $raw = [
            'type' => 'customers', 'id' => 'customer-1',
            'attributes' => [
                'name' => 'Ada', 'email' => 'ada@example.com', 'phone_number' => '+12025550123',
                'description' => null, 'created_at' => '2026-09-13T10:00:00.123456Z',
                'updated_at' => '2026-09-13T12:00:00+02:00', 'blocked_at' => null,
                'future_state' => 'new_state', 'amount_cents' => 100, 'amount_currency' => 'USD',
            ],
            'relationships' => [
                'merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']],
                'addresses' => ['data' => [['type' => 'consumer_addresses', 'id' => 'address-1']]],
                'payment_demands' => ['data' => [['type' => 'payment_demands', 'id' => 'demand-1']]],
            ],
            'links' => (object) [], 'meta' => ['source' => 'test'], 'extension' => true,
        ];
        $extra = [
            'included' => [['type' => 'consumer_addresses', 'id' => 'address-1']],
            'links' => ['next' => 'https://api.tryedge.io/v2/customers?page[number]=2'],
            'meta' => ['total' => 2],
        ];
        $result = Customer::list($this->client([$this->response([$raw], 200, $extra)]));
        $customer = $result->data[0];
        foreach (['name', 'email', 'phone_number', 'description', 'future_state', 'amount_cents', 'amount_currency'] as $field) {
            $this->assertSame($raw['attributes'][$field], $customer->$field);
        }
        $this->assertSame('123456', $customer->created_at->format('u'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $customer->updated_at);
        $this->assertNull($customer->blocked_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $customer->amount);
        $this->assertInstanceOf(Linkage::class, $customer->getRelated('merchant'));
        $this->assertInstanceOf(Linkage::class, $customer->getRelated('payment_demands')[0]);
        $this->assertSame($result->included[0], $customer->getRelated('addresses')[0]);
        $this->assertSame(ConsumerAddress::class, get_class($result->included[0]));
        $this->assertEquals(json_decode(json_encode($raw)), $customer->getRaw());
        $this->assertEquals((object) $extra['links'], $result->links);
        $this->assertSame(2, $result->meta->total);
        $this->assertSame(['customer-request'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $this->history);
        $this->expectException(\LogicException::class);
        $customer->name = 'Changed';
    }

    public function testDefaultDecoderRegistersIncludedCustomersAndAllowsLocalOverrides()
    {
        $response = new \Edge\Response(new Response(200, [], json_encode([
            'data' => ['type' => 'future_records', 'id' => '1', 'relationships' => [
                'customer' => ['data' => ['type' => 'customers', 'id' => 'customer-1']],
            ]],
            'included' => [['type' => 'customers', 'id' => 'customer-1', 'attributes' => [
                'name' => 'Ada', 'blocked_at' => '2026-09-13T10:00:00Z',
            ]]],
        ])));
        $result = (new ResourceDecoder())->decode($response, ['fields' => ['customers' => ['name', 'blocked_at']]]);
        $customer = $result->data->getRelated('customer');
        $this->assertSame($result->included[0], $customer);
        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertInstanceOf(\DateTimeImmutable::class, $customer->blocked_at);
        foreach (array_diff(Customer::FIELDS, ['name', 'blocked_at']) as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $customer->$field);
        }
        $custom = (new ResourceDecoder())->register('customers', DecodedResource::class)->decode($response);
        $this->assertInstanceOf(DecodedResource::class, $custom->included[0]);
        $this->assertInstanceOf(Customer::class, (new ResourceResult($response))->included[0]);
    }

    public function testInvalidTimestampAndSparsePrimaryFieldsRemainUnavailable()
    {
        $client = $this->client([$this->response([
            'type' => 'customers', 'id' => '1', 'attributes' => ['created_at' => 'invalid'],
        ])]);
        $customer = Customer::show($client, '1', ['fields' => ['customers' => ['created_at', 'name']]])->data;
        $this->assertEquals(new Unavailable(Unavailable::DECODING_FAILURE, 'invalid'), $customer->created_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $customer->name);
        $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $customer->email);
    }

    public function testMismatchedResourceArgumentsFailBeforeHttpAndUnsupportedMethodsAreAbsent()
    {
        $client = $this->client([]);
        foreach (['show', 'update'] as $method) {
            try {
                Customer::$method($client, new OperationResource(['type' => 'customers', 'id' => '1']));
                $this->fail('Expected a mismatched resource rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $this->history);
            }
        }
        $this->assertFalse(method_exists(Customer::class, 'confirm'));
        $this->assertFalse(method_exists(Customer::class, 'delete'));
    }

    /** @dataProvider failedOperations */
    public function testApiFailuresKeepStatusBodyAndErrors($method, $status, $body)
    {
        $client = $this->client([new Response($status, [], $body)]);
        $args = [$client];
        if (in_array($method, ['show', 'update'], true)) {
            $args[] = 'customer-1';
        }
        try {
            Customer::$method(...$args);
            $this->fail('Expected an API failure.');
        } catch (Exception $e) {
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame($body, $e->getRawBody());
            $this->assertCount(1, $this->history);
            if ($status === 422) {
                $this->assertSame('/data/attributes/email', $e->getErrors()[0]['source']['pointer']);
            }
        }
    }

    public function failedOperations()
    {
        return [
            ['list', 401, 'Unauthorized'], ['show', 404, ''],
            ['create', 422, '{"errors":[{"status":"422","detail":"Invalid email","source":{"pointer":"/data/attributes/email"}}]}'],
            ['update', 403, '{"errors":[{"status":"403","title":"Forbidden"}]}'],
        ];
    }
}
