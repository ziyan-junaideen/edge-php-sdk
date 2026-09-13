<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\ConsumerAddress;
use Edge\Customer;
use Edge\Exception;
use Edge\Linkage;
use Edge\ResourceDecoder;
use Edge\ResourceResult;
use Edge\Unavailable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ConsumerAddressTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'AddressTest/1.0',
        ]);
    }

    private function attributes()
    {
        return [
            'line_1' => '123 Main St', 'line_2' => 'Apt. 1', 'city' => 'Los Angeles',
            'state' => 'CA', 'zip' => '90001', 'country' => 'USA',
        ];
    }

    /** @dataProvider supportedOperations */
    public function testOperationsUseAddressRoutesQueriesHeadersAndExactBodies($method, $useResource)
    {
        $id = 'a/b ?#%';
        $raw = ['type' => 'consumer_addresses', 'id' => $id, 'attributes' => $this->attributes()];
        $status = $method === 'create' ? 201 : 200;
        $client = $this->client([new Response($status, [], json_encode(['data' => $method === 'list' ? [$raw] : $raw]))]);
        $query = [
            'include' => ['customer', 'merchant'], 'sort' => ['-created_at', 'city'],
            'fields' => ['consumer_addresses' => ['line_1', 'city', 'customer'], 'customers' => 'name,email'],
            'filter' => ['customer' => ['email' => 'ada@example.com']], 'page' => ['number' => 2, 'size' => 25],
        ];
        $args = [$client];
        if (in_array($method, ['show', 'update'], true)) {
            $args[] = $useResource ? new ConsumerAddress($raw) : $id;
        }
        $expectedData = ['type' => 'consumer_addresses'];
        if ($method === 'update') {
            $expectedData['id'] = $id;
        }
        if (in_array($method, ['create', 'update'], true)) {
            $attributes = $method === 'create' ? $this->attributes() : ['line_2' => null];
            $options = ['attributes' => $attributes, 'query' => $query];
            $expectedData['attributes'] = (object) $attributes;
            $expectedData['relationships'] = new \stdClass();
            if ($method === 'create') {
                $options['relationships'] = ['customer' => new Customer(['type' => 'customers', 'id' => 'customer-1'])];
                $expectedData['relationships']->customer = (object) ['data' => (object) ['type' => 'customers', 'id' => 'customer-1']];
            }
            $args[] = $options;
        } else {
            $args[] = $query;
        }
        $result = ConsumerAddress::$method(...$args);
        $request = $this->history[0]['request'];
        $expectedQuery = $query;
        $expectedQuery['include'] = 'customer,merchant';
        $expectedQuery['sort'] = '-created_at,city';
        $expectedQuery['fields']['consumer_addresses'] = 'line_1,city,customer';
        parse_str($request->getUri()->getQuery(), $sent);
        $this->assertEquals($expectedQuery, $sent);
        $this->assertSame($expectedQuery, $result->query);
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api.tryedge.io', $request->getUri()->getHost());
        $this->assertSame('/v2/consumer_addresses' . (in_array($method, ['show', 'update'], true) ? '/a%2Fb%20%3F%23%25' : ''), $request->getUri()->getPath());
        $this->assertSame(['list' => 'GET', 'show' => 'GET', 'create' => 'POST', 'update' => 'PATCH'][$method], $request->getMethod());
        $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
        $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('AddressTest/1.0', $request->getHeaderLine('User-Agent'));
        $this->assertTrue($this->history[0]['options']['verify']);
        if (in_array($method, ['create', 'update'], true)) {
            $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Content-Type'));
            $this->assertEquals((object) ['data' => (object) $expectedData], json_decode((string) $request->getBody()));
        } else {
            $this->assertSame('', (string) $request->getBody());
        }
        $this->assertInstanceOf(ResourceResult::class, $result);
        $address = $method === 'list' ? $result->data[0] : $result->data;
        $this->assertInstanceOf(ConsumerAddress::class, $address);
        $this->assertSame('123 Main St', $address->line_1);
        $this->assertSame($status, $result->status);
    }

    public function supportedOperations()
    {
        return [['list', false], ['show', false], ['show', true], ['create', false], ['update', false], ['update', true]];
    }

    public function testCustomerLinkageOnUpdateIsEncodedWithoutClaimingBackendReassignment()
    {
        $client = $this->client([new Response(204), new Response(204)]);
        ConsumerAddress::update($client, 'address-1', [
            'relationships' => ['customer' => ['type' => 'customers', 'id' => 'customer-2']],
        ]);
        ConsumerAddress::update($client, 'address-1', ['relationships' => ['customer' => null]]);
        $this->assertSame('{"data":{"type":"consumer_addresses","id":"address-1","attributes":{},"relationships":{"customer":{"data":{"type":"customers","id":"customer-2"}}}}}', (string) $this->history[0]['request']->getBody());
        $this->assertSame('{"data":{"type":"consumer_addresses","id":"address-1","attributes":{},"relationships":{"customer":{"data":null}}}}', (string) $this->history[1]['request']->getBody());
    }

    public function testAddressValuesMetadataAndCyclicIncludedCustomerResolveLocally()
    {
        $raw = [
            'type' => 'consumer_addresses', 'id' => 'address-1',
            'attributes' => $this->attributes() + [
                'created_at' => '2026-09-13T10:00:00.123456Z', 'updated_at' => '2026-09-13T12:00:00+02:00',
                'discarded_at' => null, 'future_state' => 'new_state',
                'amount_cents' => 100, 'amount_currency' => 'USD',
            ],
            'relationships' => [
                'customer' => ['data' => ['type' => 'customers', 'id' => 'customer-1']],
                'merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']],
            ],
            'links' => (object) [], 'meta' => ['source' => 'test'], 'extension' => true,
        ];
        $document = [
            'data' => $raw,
            'included' => [['type' => 'customers', 'id' => 'customer-1', 'relationships' => [
                'addresses' => ['data' => [['type' => 'consumer_addresses', 'id' => 'address-1']]],
            ]]],
            'links' => ['self' => '/v2/consumer_addresses/address-1'], 'meta' => ['example' => true],
        ];
        $client = $this->client([new Response(200, ['X-Request-Id' => 'address-request'], json_encode($document))]);
        $result = ConsumerAddress::show($client, 'address-1', ['include' => 'customer']);
        $address = $result->data;
        foreach ($this->attributes() as $field => $value) {
            $this->assertSame($value, $address->$field);
        }
        $this->assertSame('123456', $address->created_at->format('u'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $address->updated_at);
        $this->assertNull($address->discarded_at);
        $this->assertSame('new_state', $address->future_state);
        $this->assertSame(100, $address->amount_cents);
        foreach (['amount', 'postal_code', 'address_line_1', 'country_code'] as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $address->$field);
        }
        $customer = $address->getRelated('customer');
        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertSame($result->included[0], $customer);
        $this->assertSame($address, $customer->getRelated('addresses')[0]);
        $this->assertInstanceOf(Linkage::class, $address->getRelated('merchant'));
        $this->assertEquals(json_decode(json_encode($raw)), $address->getRaw());
        $this->assertEquals((object) $document['links'], $result->links);
        $this->assertTrue($result->meta->example);
        $this->assertSame(['address-request'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $this->history);
        $this->expectException(\LogicException::class);
        $address->city = 'Changed';
    }

    public function testIncludedAddressesUseDefaultSchemaAndSparseFieldsWithLocalRegistryOverrides()
    {
        $response = new \Edge\Response(new Response(200, [], json_encode([
            'data' => ['type' => 'customers', 'id' => 'customer-1', 'relationships' => [
                'addresses' => ['data' => [['type' => 'consumer_addresses', 'id' => 'address-1']]],
            ]],
            'included' => [['type' => 'consumer_addresses', 'id' => 'address-1', 'attributes' => [
                'city' => 'Los Angeles', 'discarded_at' => '2026-09-13T10:00:00Z',
            ]]],
        ])));
        $query = ['fields' => ['consumer_addresses' => 'city,discarded_at']];
        $result = (new ResourceDecoder())->decode($response, $query);
        $address = $result->data->getRelated('addresses')[0];
        $this->assertInstanceOf(ConsumerAddress::class, $address);
        $this->assertSame($result->included[0], $address);
        $this->assertInstanceOf(\DateTimeImmutable::class, $address->discarded_at);
        foreach (array_diff(ConsumerAddress::FIELDS, ['city', 'discarded_at']) as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $address->$field);
        }
        $custom = (new ResourceDecoder())->register('consumer_addresses', DecodedResource::class)->decode($response);
        $this->assertInstanceOf(DecodedResource::class, $custom->included[0]);
        $this->assertInstanceOf(ConsumerAddress::class, (new ResourceResult($response))->included[0]);
    }

    public function testSparsePrimaryAddressInvalidDatesEmptyCollectionsAndNullData()
    {
        $client = $this->client([
            new Response(200, [], '{"data":{"type":"consumer_addresses","id":"1","attributes":{"created_at":"invalid","updated_at":null,"discarded_at":"2026-02-30T10:00:00Z"}}}'),
            new Response(200, [], '{"data":[],"links":{"next":"https://api.tryedge.io/v2/consumer_addresses?page[number]=2"}}'),
            new Response(204),
        ]);
        $address = ConsumerAddress::show($client, '1', ['fields' => ['consumer_addresses' => ['created_at', 'updated_at', 'discarded_at', 'city']]])->data;
        $this->assertSame(Unavailable::DECODING_FAILURE, $address->created_at->reason);
        $this->assertSame(Unavailable::DECODING_FAILURE, $address->discarded_at->reason);
        $this->assertNull($address->updated_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $address->city);
        $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $address->country);
        $page = ConsumerAddress::list($client);
        $this->assertSame([], $page->data);
        $this->assertSame('https://api.tryedge.io/v2/consumer_addresses?page[number]=2', $page->links->next);
        $this->assertCount(2, $this->history);
        $this->assertNull(ConsumerAddress::show($client, '1')->data);
    }

    public function testMismatchedResourcesFailBeforeHttpAndUnsupportedOperationsAreAbsent()
    {
        $client = $this->client([]);
        foreach (['show', 'update'] as $method) {
            foreach ([new Customer(['type' => 'consumer_addresses', 'id' => '1']), new ConsumerAddress(['type' => 'customers', 'id' => '1'])] as $resource) {
                try {
                    ConsumerAddress::$method($client, $resource);
                    $this->fail('Expected mismatched resource rejection.');
                } catch (\InvalidArgumentException $e) {
                    $this->assertSame([], $this->history);
                }
            }
        }
        $this->assertFalse(method_exists(ConsumerAddress::class, 'confirm'));
        $this->assertFalse(method_exists(ConsumerAddress::class, 'delete'));
    }

    /** @dataProvider failedOperations */
    public function testApiFailuresRetainResponseDetails($method, $status, $body)
    {
        $client = $this->client([new Response($status, [], $body)]);
        $args = [$client];
        if (in_array($method, ['show', 'update'], true)) {
            $args[] = 'address-1';
        }
        try {
            ConsumerAddress::$method(...$args);
            $this->fail('Expected API failure.');
        } catch (Exception $e) {
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame($body, $e->getRawBody());
            $this->assertCount(1, $this->history);
            if ($status === 422) {
                $this->assertSame('/data/attributes/country', $e->getErrors()[0]['source']['pointer']);
            }
        }
    }

    public function failedOperations()
    {
        return [
            ['list', 401, 'Unauthorized'], ['show', 404, ''],
            ['create', 422, '{"errors":[{"status":"422","detail":"Invalid country","source":{"pointer":"/data/attributes/country"}}]}'],
            ['update', 403, '{"errors":[{"status":"403","title":"Forbidden"}]}'],
        ];
    }
}
