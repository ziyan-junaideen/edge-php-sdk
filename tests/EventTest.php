<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\Customer;
use Edge\Exception;
use Edge\Event;
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

class EventTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'EventTest/1.0',
        ]);
    }

    /** @dataProvider readOperations */
    public function testReadsUseEventRoutesQueriesHeadersAndEmptyBodies($operation, $useResource)
    {
        $raw = ['type' => 'events', 'id' => 'a/b ?#%'];
        $client = $this->client([new Response(200, [], json_encode(['data' => $operation === 'list' ? [$raw] : $raw]))]);
        $query = [
            'include' => ['merchant'], 'sort' => ['-created_at', 'slug'],
            'fields' => ['events' => ['slug', 'resource_type', 'merchant'], 'merchants' => 'business_name'],
            'filter' => ['merchant' => ['business_name' => 'Example']], 'page' => ['number' => 2, 'size' => 25],
        ];
        $result = $operation === 'list' ? Event::list($client, $query)
            : Event::show($client, $useResource ? new Event($raw) : $raw['id'], $query);
        $request = $this->history[0]['request'];
        $expectedQuery = $query;
        $expectedQuery['include'] = 'merchant';
        $expectedQuery['sort'] = '-created_at,slug';
        $expectedQuery['fields']['events'] = 'slug,resource_type,merchant';
        parse_str($request->getUri()->getQuery(), $sent);
        $this->assertEquals($expectedQuery, $sent);
        $this->assertSame($expectedQuery, $result->query);
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api.tryedge.io', $request->getUri()->getHost());
        $this->assertSame('/v2/events' . ($operation === 'list' ? '' : '/a%2Fb%20%3F%23%25'), $request->getUri()->getPath());
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
        $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('EventTest/1.0', $request->getHeaderLine('User-Agent'));
        $this->assertTrue($this->history[0]['options']['verify']);
        $this->assertInstanceOf(ResourceResult::class, $result);
        $this->assertInstanceOf(Event::class, $operation === 'list' ? $result->data[0] : $result->data);
        $this->assertSame(200, $result->status);
    }

    public function readOperations()
    {
        return [['list', false], ['show', false], ['show', true]];
    }

    public function testEventMetadataAndOpaquePayloadArePreserved()
    {
        $payload = [
            'type' => 'payment_demands', 'id' => 'demand-1',
            'attributes' => ['created_at' => '2026-09-13T10:00:00Z', 'updated_at' => 'invalid',
                'amount_cents' => 1234, 'amount_currency' => 'USD', 'nullable' => null],
            'relationships' => ['merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']]],
            'empty_object' => (object) [], 'empty_array' => [],
            'nested' => [['type' => 'events', 'id' => 'event-1'], true, 42, 'text', null],
        ];
        $attributes = [
            'data' => $payload, 'mode' => 'future_mode', 'resource_id' => 'demand-1',
            'resource_type' => 'future.resource', 'slug' => 'future_event',
            'created_at' => '2026-09-13T10:00:00.123456Z', 'updated_at' => '2026-09-13T11:00:00Z',
            'amount_cents' => 100, 'amount_currency' => 'USD', 'future_field' => ['kept' => true],
        ];
        $raw = [
            'type' => 'events', 'id' => 'event-1', 'attributes' => $attributes,
            'relationships' => ['merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']]],
            'links' => (object) [], 'meta' => ['source' => 'test'], 'extension' => true,
        ];
        $document = ['data' => $raw, 'included' => [
            ['type' => 'merchants', 'id' => 'merchant-1', 'attributes' => ['business_name' => 'Example']],
        ], 'links' => ['self' => '/v2/events/event-1'], 'meta' => ['example' => true]];
        $client = $this->client([new Response(200, ['X-Request-Id' => 'event-request'], json_encode($document))]);
        $result = Event::show($client, 'event-1', ['include' => 'merchant']);
        $event = $result->data;
        foreach (['mode', 'resource_id', 'resource_type', 'slug', 'updated_at', 'amount_cents', 'amount_currency'] as $field) {
            $this->assertSame($attributes[$field], $event->$field);
        }
        $this->assertTrue($event->future_field->kept);
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->created_at);
        $this->assertSame('123456', $event->created_at->format('u'));
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $event->amount);
        $this->assertSame(json_encode($payload), json_encode($event->data));
        $this->assertInstanceOf(\stdClass::class, $event->data);
        $this->assertInstanceOf(\stdClass::class, $event->data->nested[0]);
        $copy = $event->data;
        $copy->attributes->amount_cents = 0;
        $this->assertSame(1234, $event->data->attributes->amount_cents);
        $this->assertInstanceOf(\Edge\Merchant::class, $event->getRelated('merchant'));
        $this->assertSame($result->included[0], $event->getRelated('merchant'));
        $this->assertEquals(json_decode(json_encode($raw)), $event->getRaw());
        $this->assertEquals(json_decode(json_encode($document)), $result->raw);
        $this->assertEquals((object) $document['links'], $result->links);
        $this->assertTrue($result->meta->example);
        $this->assertSame(['event-request'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $this->history);
        $this->expectException(\LogicException::class);
        $event->data = [];
    }

    public function testIncludedEventsUseDefaultSchemaSparseFieldsAndLocalOverrides()
    {
        $response = new \Edge\Response(new Response(200, [], json_encode([
            'data' => ['type' => 'future_records', 'id' => '1', 'relationships' => [
                'event' => ['data' => ['type' => 'events', 'id' => 'event-1']],
            ]],
            'included' => [['type' => 'events', 'id' => 'event-1', 'attributes' => [
                'slug' => 'future_event', 'created_at' => '2026-09-13T10:00:00Z',
            ]]],
        ])));
        $result = (new ResourceDecoder())->decode($response, ['fields' => ['events' => 'slug,created_at']]);
        $event = $result->data->getRelated('event');
        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame($result->included[0], $event);
        $this->assertSame('future_event', $event->slug);
        $this->assertInstanceOf(\DateTimeImmutable::class, $event->created_at);
        foreach (['data', 'mode', 'resource_id', 'resource_type', 'merchant'] as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $event->$field);
        }
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $event->updated_at);
        $custom = (new ResourceDecoder())->register('events', DecodedResource::class)->decode($response);
        $this->assertInstanceOf(DecodedResource::class, $custom->included[0]);
        $this->assertInstanceOf(Event::class, (new ResourceResult($response))->included[0]);
    }

    public function testSparseFieldsNullsMissingLinkageAndExplicitPagination()
    {
        $client = $this->client([
            new Response(200, [], '{"data":{"type":"events","id":"1","attributes":{"created_at":"2026-02-30T10:00:00Z","data":null},"relationships":{"merchant":{"data":null}}}}'),
            new Response(200, [], '{"data":{"type":"events","id":"2","attributes":{"created_at":null},"relationships":{"merchant":{"data":{"type":"merchants","id":"missing"}}}}}'),
            new Response(200, [], '{"data":[],"links":{"next":"https://api.tryedge.io/v2/events?page[number]=2"}}'),
            new Response(204),
        ]);
        $event = Event::show($client, '1', ['fields' => ['events' => ['created_at', 'data', 'slug', 'merchant']]])->data;
        $this->assertSame(Unavailable::DECODING_FAILURE, $event->created_at->reason);
        $this->assertNull($event->data);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $event->slug);
        $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $event->mode);
        $this->assertNull($event->getRelated('merchant'));
        $other = Event::show($client, '2')->data;
        $this->assertNull($other->created_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $other->data);
        $this->assertInstanceOf(\Edge\Linkage::class, $other->getRelated('merchant'));
        $this->assertSame('missing', $other->getRelated('merchant')->id);
        $page = Event::list($client);
        $this->assertSame([], $page->data);
        $this->assertSame('https://api.tryedge.io/v2/events?page[number]=2', $page->links->next);
        $this->assertCount(3, $this->history);
        $this->assertNull(Event::show($client, '1')->data);
    }

    public function testMismatchedResourcesAreRejectedAndWriteOperationsAreAbsent()
    {
        $client = $this->client([]);
        foreach ([new Customer(['type' => 'events', 'id' => '1']), new Event(['type' => 'customers', 'id' => '1'])] as $resource) {
            try {
                Event::show($client, $resource);
                $this->fail('Expected mismatched resource rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $this->history);
            }
        }
        foreach (['create', 'update', 'delete', 'confirm'] as $operation) {
            $this->assertFalse(method_exists(Event::class, $operation));
        }
    }

    /** @dataProvider failedReads */
    public function testApiFailuresRetainResponseDetails($operation, $status, $body)
    {
        $client = $this->client([new Response($status, [], $body)]);
        try {
            $operation === 'list' ? Event::list($client) : Event::show($client, 'event-1');
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
        $failure = new ConnectException('Connection failed', new Request('GET', 'https://api.tryedge.io/v2/events'));
        $client = $this->client([$failure]);
        try {
            Event::list($client);
            $this->fail('Expected network failure.');
        } catch (Exception $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertSame($failure, $e->getPrevious());
        }
    }
}
