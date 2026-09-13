<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\ApiResource;
use Edge\Exception;
use Edge\Linkage;
use Edge\Money;
use Edge\Resource;
use Edge\ResourceResult;
use Edge\Unavailable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ResourceOperationTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'TestApp/1.0',
        ]);
    }

    public function testEveryOperationUsesTheCorrectEnvelopeTransportAndResult()
    {
        $client = $this->client(array_fill(0, 5, new Response(200, [], '{"data":null}')));
        $resource = new OperationResource(['type' => 'test_records', 'id' => 'a/b ?#%']);
        $results = [
            OperationResource::list($client),
            OperationResource::show($client, $resource),
            OperationResource::create($client),
            OperationResource::update($client, $resource),
            OperationResource::confirm($client, $resource),
        ];
        $paths = ['test_records', 'test_records/a%2Fb%20%3F%23%25', 'test_records',
            'test_records/a%2Fb%20%3F%23%25', 'test_records/a%2Fb%20%3F%23%25/confirm'];
        foreach (['GET', 'GET', 'POST', 'PATCH', 'PATCH'] as $i => $verb) {
            $request = $this->history[$i]['request'];
            $this->assertInstanceOf(ResourceResult::class, $results[$i]);
            $this->assertNull($results[$i]->data);
            $this->assertSame($verb, $request->getMethod());
            $this->assertSame(ApiClient::DEFAULT_BASE_URI . $paths[$i], (string) $request->getUri());
            $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
            $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
            $this->assertStringContainsString('TestApp/1.0', $request->getHeaderLine('User-Agent'));
            $this->assertTrue($this->history[$i]['options']['verify']);
            if ($i < 2) {
                $this->assertSame('', (string) $request->getBody());
                continue;
            }
            $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Content-Type'));
            $expected = ['type' => 'test_records'];
            if ($i > 2) {
                $expected['id'] = $resource->id;
            }
            $expected['attributes'] = new \stdClass();
            if ($i !== 4) {
                $expected['relationships'] = new \stdClass();
            }
            $this->assertEquals((object) ['data' => (object) $expected], json_decode((string) $request->getBody()));
        }
    }

    public function testWritesPreserveRelationshipCardinalityNullsAndLinkageMetadata()
    {
        $client = $this->client([new Response(201), new Response(200)]);
        $resource = new Resource(['type' => 'buyers', 'id' => 'buyer-1', 'attributes' => ['name' => 'Ada']]);
        $linkage = new Linkage(['type' => 'buyers', 'id' => 'buyer-2', 'meta' => (object) ['role' => 'payer']]);
        $options = [
            'attributes' => ['name' => 'Ada', 'unknown' => null, 'items' => [], 'settings' => new \stdClass()],
            'relationships' => [
                'buyer' => $resource,
                'payer' => $linkage,
                'explicit' => ['type' => 'buyers', 'id' => 'buyer-3'],
                'object' => (object) ['type' => 'buyers', 'id' => 'buyer-4'],
                'many' => [$resource, $linkage, ['type' => 'buyers', 'id' => 'buyer-3']],
                'oneInMany' => [$resource],
                'empty' => [],
                'cleared' => null,
            ],
            'query' => ['include' => ['buyer'], 'fields' => ['test_records' => ['name']]],
        ];
        $this->assertSame(201, OperationResource::create($client, $options)->status);
        OperationResource::update($client, 'record-1', $options);
        foreach ($this->history as $entry) {
            $data = json_decode((string) $entry['request']->getBody())->data;
            $this->assertEquals((object) $options['attributes'], $data->attributes);
            $this->assertFalse(property_exists($data, 'query'));
            $this->assertEquals((object) ['type' => 'buyers', 'id' => 'buyer-1'], $data->relationships->buyer->data);
            $this->assertEquals((object) $linkage->getRaw(), $data->relationships->payer->data);
            $this->assertSame('buyer-3', $data->relationships->explicit->data->id);
            $this->assertSame('buyer-4', $data->relationships->object->data->id);
            $this->assertCount(3, $data->relationships->many->data);
            $this->assertCount(1, $data->relationships->oneInMany->data);
            $this->assertSame([], $data->relationships->empty->data);
            $this->assertNull($data->relationships->cleared->data);
            $this->assertSame('include=buyer&fields%5Btest_records%5D=name', $entry['request']->getUri()->getQuery());
        }
    }

    /** @dataProvider operations */
    public function testAllOperationsNormalizeQueryWithoutChangingNestedParameters($operation)
    {
        $client = $this->client([new Response(200)]);
        $query = [
            'include' => ['buyer', 'buyer.addresses'], 'sort' => ['-created_at', 'name'],
            'fields' => ['test_records' => ['name', 'buyer'], 'buyers' => 'name,email'],
            'filter' => ['buyer' => ['email' => 'a+b@example.com']],
            'page' => ['number' => 2, 'size' => 25, 'limit' => 5, 'offset' => 10], 'custom' => 'kept',
        ];
        $args = [$client];
        if (in_array($operation, ['show', 'update', 'confirm'], true)) {
            $args[] = 'record';
        }
        $args[] = in_array($operation, ['create', 'update'], true) ? ['query' => $query] : $query;
        $result = OperationResource::$operation(...$args);
        $expected = $query;
        $expected['include'] = 'buyer,buyer.addresses';
        $expected['sort'] = '-created_at,name';
        $expected['fields']['test_records'] = 'name,buyer';
        parse_str($this->history[0]['request']->getUri()->getQuery(), $sent);
        $this->assertEquals($expected, $sent);
        $this->assertSame($expected, $result->query);
        $this->assertSame(['buyer', 'buyer.addresses'], $query['include']);
    }

    public function operations()
    {
        return [['list'], ['show'], ['create'], ['update'], ['confirm']];
    }

    public function testStringAndEmptyQueryLists()
    {
        $client = $this->client([new Response(200)]);
        $result = OperationResource::list($client, [
            'include' => 'buyer.addresses', 'sort' => '', 'fields' => ['test_records' => []],
        ]);
        $this->assertSame(['include' => 'buyer.addresses', 'sort' => '', 'fields' => ['test_records' => '']], $result->query);
        $this->assertSame('include=buyer.addresses&sort=&fields%5Btest_records%5D=', $this->history[0]['request']->getUri()->getQuery());
    }

    public function testRichDecodingIncludesSparseFieldsMetadataAndExplicitPagination()
    {
        $body = '{"data":[{"type":"test_records","id":"1","attributes":{"amount_cents":1200,"amount_currency":"USD","created_at":"2026-09-13T10:00:00Z","future":"kept"},"relationships":{"buyer":{"data":{"type":"buyers","id":"2"}}}}],"included":[{"type":"buyers","id":"2","attributes":{"name":"Ada"}}],"links":{"next":{"href":"https://api.tryedge.io/v2/test_records?page[number]=2","meta":{}}},"meta":{"total":2},"future":true}';
        $client = $this->client([new Response(200, ['X-Request-Id' => 'first'], $body), new Response(204)]);
        $result = OperationResource::list($client, ['fields' => ['test_records' => ['amount_cents', 'amount_currency', 'created_at', 'buyer']]]);
        $record = $result->data[0];
        $this->assertInstanceOf(OperationResource::class, $record);
        $this->assertInstanceOf(Money::class, $record->amount);
        $this->assertInstanceOf(\DateTimeImmutable::class, $record->created_at);
        $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $record->name);
        $this->assertSame('kept', $record->future);
        $this->assertSame($result->included[0], $record->getRelated('buyer'));
        $this->assertSame(Resource::class, get_class($result->included[0]));
        $this->assertCount(1, $this->history);
        $this->assertEquals(json_decode($body)->links, $result->links);
        $this->assertEquals(json_decode($body)->meta, $result->meta);
        $this->assertEquals(json_decode($body), $result->raw);
        $this->assertNull(OperationResource::show($client, '1')->data);
        $this->assertSame(200, $result->status);
        $this->assertSame(['first'], $result->headers['X-Request-Id']);
    }

    public function testSingletonIsDecodedAsTheCalledClassAndEmptyCollectionStaysAnArray()
    {
        $client = $this->client([
            new Response(200, [], '{"data":{"type":"test_records","id":"1"}}'),
            new Response(200, [], '{"data":[]}'),
        ]);
        $this->assertInstanceOf(OperationResource::class, OperationResource::show($client, '1')->data);
        $this->assertSame([], OperationResource::list($client)->data);
    }

    /** @dataProvider invalidIdentities */
    public function testRejectsInvalidOrMismatchedIdentityBeforeHttp($identity)
    {
        $client = $this->client([]);
        foreach (['show', 'update', 'confirm'] as $operation) {
            try {
                OperationResource::$operation($client, $identity);
                $this->fail('Expected identity rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $this->history);
            }
        }
    }

    public function invalidIdentities()
    {
        return [
            [new ReadOnlyOperationResource(['type' => 'test_read_only', 'id' => '1'])],
            [new ReadOnlyOperationResource(['type' => 'test_records', 'id' => '1'])],
            [new OperationResource(['type' => 'wrong', 'id' => '1'])],
            [new Resource(['type' => 'test_records', 'id' => '1'])],
            [new OperationResource(['type' => 'test_records'])],
            [''], [null], [1], [[]],
        ];
    }

    /** @dataProvider opaqueIds */
    public function testStringIdsAreOneOpaquePathSegment($id, $segment)
    {
        $client = $this->client([new Response(200)]);
        OperationResource::confirm($client, $id);
        $this->assertSame('/v2/test_records/' . $segment . '/confirm', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame($id, json_decode((string) $this->history[0]['request']->getBody())->data->id);
    }

    public function opaqueIds()
    {
        return [['.', '%2E'], ['..', '%2E%2E'], ['0', '0'], ['a/b ?#%', 'a%2Fb%20%3F%23%25'], ['é', '%C3%A9']];
    }

    /** @dataProvider invalidWrites */
    public function testMalformedWritesAreRejectedBeforeHttp($options)
    {
        $client = $this->client([]);
        try {
            OperationResource::create($client, $options);
            $this->fail('Expected invalid input rejection.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame([], $this->history);
        }
    }

    public function invalidWrites()
    {
        return [
            [['name' => 'misplaced']], [['attributes' => ['not a map']]],
            [['relationships' => ['buyer' => ['id' => 'missing type']]]],
            [['relationships' => ['buyer' => [null]]]],
            [['query' => ['include' => [123]]]], [['query' => ['sort' => false]]],
            [['query' => ['fields' => ['test_records' => [new \stdClass()]]]]],
        ];
    }

    public function testUnsupportedOperationsAreAbsent()
    {
        foreach (['create', 'update', 'confirm', 'delete'] as $method) {
            $this->assertFalse(method_exists(ReadOnlyOperationResource::class, $method));
        }
        foreach (['list', 'show', 'create', 'update', 'confirm', 'delete'] as $method) {
            $this->assertFalse(method_exists(Resource::class, $method));
            $this->assertFalse(method_exists(ApiResource::class, $method));
        }
        $this->assertTrue(method_exists(ReadOnlyOperationResource::class, 'list'));
        $this->assertTrue(method_exists(ReadOnlyOperationResource::class, 'show'));
    }

    public function testMalformedSuccessfulDocumentsStillRaiseEdgeExceptions()
    {
        $client = $this->client([new Response(200, [], 'not json')]);
        $this->expectException(Exception::class);
        OperationResource::create($client);
    }
}
