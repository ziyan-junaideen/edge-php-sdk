<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\Merchant;
use Edge\WebhookSubscription;
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

class WebhookSubscriptionTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'WebhookSubscriptionTest/1.0',
        ]);
    }

    private function response($data, $status = 200, array $extra = [])
    {
        return new Response($status, ['X-Request-Id' => 'subscription-request'], json_encode(['data' => $data] + $extra));
    }

    public function testSupportedOperationsUseWebhookSubscriptionRoutesHeadersAndExactWriteBodies()
    {
        $raw = ['type' => 'webhook_subscriptions', 'id' => 'a/b ?#%', 'attributes' => ['description' => 'Example webhook']];
        $client = $this->client([
            $this->response([$raw]), $this->response($raw), $this->response($raw, 201),
            $this->response($raw), $this->response($raw), $this->response($raw),
        ]);
        $subscription = new WebhookSubscription($raw);
        $query = [
            'include' => ['webhook_deliveries', 'merchant'], 'sort' => ['-created_at', 'description'],
            'fields' => ['webhook_subscriptions' => ['description', 'events']],
            'filter' => ['mode' => ['eq' => 'sandbox']], 'page' => ['size' => 25],
        ];
        $attributes = ['mode' => 'sandbox', 'url' => 'https://example.com/webhooks/edge',
            'concurrency_limit' => 5, 'description' => 'Example webhook',
            'events' => ['transaction.payment_demands.succeeded', 'future.event']];
        $results = [
            WebhookSubscription::list($client, $query),
            WebhookSubscription::show($client, $subscription, $query),
            WebhookSubscription::create($client, ['attributes' => $attributes, 'query' => $query]),
            WebhookSubscription::update($client, $subscription, ['attributes' => $attributes, 'query' => $query]),
            WebhookSubscription::show($client, $subscription->id, $query),
            WebhookSubscription::update($client, $subscription->id, ['query' => $query]),
        ];
        $expectedQuery = $query;
        $expectedQuery['include'] = 'webhook_deliveries,merchant';
        $expectedQuery['sort'] = '-created_at,description';
        $expectedQuery['fields']['webhook_subscriptions'] = 'description,events';
        foreach (['GET', 'GET', 'POST', 'PATCH', 'GET', 'PATCH'] as $i => $method) {
            $request = $this->history[$i]['request'];
            $this->assertSame($method, $request->getMethod());
            $this->assertSame('https', $request->getUri()->getScheme());
            $this->assertSame('api.tryedge.io', $request->getUri()->getHost());
            $this->assertSame('/v2/webhook_subscriptions' . (in_array($i, [0, 2], true) ? '' : '/a%2Fb%20%3F%23%25'), $request->getUri()->getPath());
            $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
            $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
            $this->assertStringContainsString('WebhookSubscriptionTest/1.0', $request->getHeaderLine('User-Agent'));
            $this->assertTrue($this->history[$i]['options']['verify']);
            parse_str($request->getUri()->getQuery(), $sent);
            $this->assertEquals($expectedQuery, $sent);
            $this->assertInstanceOf(ResourceResult::class, $results[$i]);
            $this->assertInstanceOf(WebhookSubscription::class, $i === 0 ? $results[$i]->data[0] : $results[$i]->data);
            $this->assertSame($i === 2 ? 201 : 200, $results[$i]->status);
            if ($method === 'GET') {
                $this->assertSame('', (string) $request->getBody());
            } else {
                $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Content-Type'));
                $expected = ['type' => 'webhook_subscriptions'];
                if ($i !== 2) {
                    $expected['id'] = $subscription->id;
                }
                $expected['attributes'] = (object) (in_array($i, [2, 3], true) ? $attributes : []);
                $expected['relationships'] = new \stdClass();
                $this->assertEquals((object) ['data' => (object) $expected], json_decode((string) $request->getBody()));
            }
        }
    }

    public function testArchivingAndEmptyWritesUseSharedEnvelopes()
    {
        $client = $this->client([$this->response(null), $this->response(null, 201)]);
        WebhookSubscription::update($client, 'subscription-1', ['attributes' => ['status' => 'archived']]);
        WebhookSubscription::create($client);
        $this->assertSame('{"data":{"type":"webhook_subscriptions","id":"subscription-1","attributes":{"status":"archived"},"relationships":{}}}', (string) $this->history[0]['request']->getBody());
        $this->assertSame('{"data":{"type":"webhook_subscriptions","attributes":{},"relationships":{}}}', (string) $this->history[1]['request']->getBody());
    }

    public function testDecodingPreservesConfigurationRelationshipsAndResponseMetadata()
    {
        $attributes = [
            'status' => 'future_status', 'mode' => 'future_mode', 'concurrency_limit' => 5,
            'description' => null, 'events' => ['transaction.payment_demands.succeeded', 'future.event'],
            'secret_key' => 'fake-webhook-secret-for-tests', 'url' => 'https://example.com/webhooks/edge',
            'created_at' => '2026-09-13T10:00:00.123456Z', 'updated_at' => '2026-09-13T12:00:00+02:00',
            'archived_at' => null, 'future_field' => ['kept' => true],
            'amount_cents' => 100, 'amount_currency' => 'USD',
        ];
        $raw = [
            'type' => 'webhook_subscriptions', 'id' => 'subscription-1', 'attributes' => $attributes,
            'relationships' => [
                'merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']],
                'webhook_deliveries' => ['data' => [
                    ['type' => 'webhook_deliveries', 'id' => 'delivery-1'],
                    ['type' => 'webhook_deliveries', 'id' => 'missing'],
                ]],
            ],
            'links' => (object) [], 'meta' => ['source' => 'test'], 'extension' => true,
        ];
        $extra = [
            'included' => [
                ['type' => 'merchants', 'id' => 'merchant-1'],
                ['type' => 'webhook_deliveries', 'id' => 'delivery-1', 'relationships' => [
                    'webhook_subscription' => ['data' => ['type' => 'webhook_subscriptions', 'id' => 'subscription-1']],
                ]],
            ],
            'links' => ['next' => 'https://api.tryedge.io/v2/webhook_subscriptions?page[number]=2'],
            'meta' => ['total' => 2],
        ];
        $result = WebhookSubscription::list($this->client([$this->response([$raw], 200, $extra)]));
        $subscription = $result->data[0];
        foreach (array_diff(array_keys($attributes), ['created_at', 'updated_at', 'future_field']) as $field) {
            $this->assertSame($attributes[$field], $subscription->$field);
        }
        $this->assertTrue($subscription->future_field->kept);
        $this->assertSame('123456', $subscription->created_at->format('u'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $subscription->updated_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $subscription->amount);
        $this->assertInstanceOf(Merchant::class, $subscription->getRelated('merchant'));
        $deliveries = $subscription->getRelated('webhook_deliveries');
        $this->assertSame(Resource::class, get_class($deliveries[0]));
        $this->assertSame($result->included[1], $deliveries[0]);
        $this->assertSame($subscription, $deliveries[0]->getRelated('webhook_subscription'));
        $this->assertInstanceOf(Linkage::class, $deliveries[1]);
        $this->assertEquals(json_decode(json_encode($raw)), $subscription->getRaw());
        $this->assertEquals(json_decode(json_encode(['data' => [$raw]] + $extra)), $result->raw);
        $this->assertEquals((object) $extra['links'], $result->links);
        $this->assertSame(2, $result->meta->total);
        $this->assertSame(['subscription-request'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $this->history);
        $this->expectException(\LogicException::class);
        $subscription->secret_key = 'changed';
    }

    public function testDefaultDecoderRegistersIncludedWebhookSubscriptionsAndAllowsLocalOverrides()
    {
        $response = new \Edge\Response(new Response(200, [], json_encode([
            'data' => ['type' => 'future_records', 'id' => '1', 'relationships' => [
                'webhook_subscription' => ['data' => ['type' => 'webhook_subscriptions', 'id' => 'subscription-1']],
            ]],
            'included' => [['type' => 'webhook_subscriptions', 'id' => 'subscription-1', 'attributes' => [
                'description' => 'Example webhook', 'archived_at' => '2026-09-13T10:00:00Z',
            ]]],
        ])));
        $result = (new ResourceDecoder())->decode($response, ['fields' => ['webhook_subscriptions' => ['description', 'archived_at']]]);
        $subscription = $result->data->getRelated('webhook_subscription');
        $this->assertSame($result->included[0], $subscription);
        $this->assertInstanceOf(WebhookSubscription::class, $subscription);
        $this->assertInstanceOf(\DateTimeImmutable::class, $subscription->archived_at);
        foreach (array_diff(WebhookSubscription::FIELDS, ['description', 'archived_at']) as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $subscription->$field);
        }
        $custom = (new ResourceDecoder())->register('webhook_subscriptions', DecodedResource::class)->decode($response);
        $this->assertInstanceOf(DecodedResource::class, $custom->included[0]);
        $this->assertInstanceOf(WebhookSubscription::class, (new ResourceResult($response))->included[0]);
    }

    public function testInvalidTimestampAndSparsePrimaryFieldsRemainUnavailable()
    {
        $client = $this->client([$this->response([
            'type' => 'webhook_subscriptions', 'id' => '1', 'attributes' => ['created_at' => 'invalid'],
        ])]);
        $subscription = WebhookSubscription::show($client, '1', ['fields' => ['webhook_subscriptions' => ['created_at', 'description']]])->data;
        $this->assertEquals(new Unavailable(Unavailable::DECODING_FAILURE, 'invalid'), $subscription->created_at);
        $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $subscription->description);
        $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $subscription->events);
    }

    public function testInvalidArchiveDateNullRelationshipsAndEmptyResults()
    {
        $client = $this->client([
            $this->response(['type' => 'webhook_subscriptions', 'id' => '1', 'attributes' => [
                'archived_at' => '2026-02-30T10:00:00Z', 'updated_at' => null, 'events' => [],
            ], 'relationships' => ['merchant' => ['data' => null], 'webhook_deliveries' => ['data' => []]]]),
            $this->response([]), new Response(204),
        ]);
        $subscription = WebhookSubscription::show($client, '1')->data;
        $this->assertSame(Unavailable::DECODING_FAILURE, $subscription->archived_at->reason);
        $this->assertNull($subscription->updated_at);
        $this->assertSame([], $subscription->events);
        $this->assertNull($subscription->getRelated('merchant'));
        $this->assertSame([], $subscription->getRelated('webhook_deliveries'));
        $this->assertSame([], WebhookSubscription::list($client)->data);
        $this->assertNull(WebhookSubscription::show($client, '1')->data);
        $this->assertCount(3, $this->history);
    }

    public function testNetworkFailureKeepsUnderlyingException()
    {
        $failure = new \GuzzleHttp\Exception\ConnectException('Connection failed',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.tryedge.io/v2/webhook_subscriptions'));
        $client = $this->client([$failure]);
        try {
            WebhookSubscription::create($client);
            $this->fail('Expected network failure.');
        } catch (Exception $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertSame($failure, $e->getPrevious());
        }
    }

    public function testMismatchedResourceArgumentsFailBeforeHttpAndUnsupportedMethodsAreAbsent()
    {
        $client = $this->client([]);
        foreach (['show', 'update'] as $method) {
            try {
                WebhookSubscription::$method($client, new OperationResource(['type' => 'webhook_subscriptions', 'id' => '1']));
                $this->fail('Expected a mismatched resource rejection.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $this->history);
            }
        }
        $this->assertFalse(method_exists(WebhookSubscription::class, 'confirm'));
        $this->assertFalse(method_exists(WebhookSubscription::class, 'delete'));
    }

    /** @dataProvider failedOperations */
    public function testApiFailuresKeepStatusBodyAndErrors($method, $status, $body)
    {
        $client = $this->client([new Response($status, [], $body)]);
        $args = [$client];
        if (in_array($method, ['show', 'update'], true)) {
            $args[] = 'subscription-1';
        }
        try {
            WebhookSubscription::$method(...$args);
            $this->fail('Expected an API failure.');
        } catch (Exception $e) {
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame($body, $e->getRawBody());
            $this->assertCount(1, $this->history);
            if ($status === 422) {
                $this->assertSame('/data/attributes/url', $e->getErrors()[0]['source']['pointer']);
            }
        }
    }

    public function failedOperations()
    {
        return [
            ['list', 401, 'Unauthorized'], ['show', 404, ''],
            ['create', 422, '{"errors":[{"status":"422","detail":"Invalid URL","source":{"pointer":"/data/attributes/url"}}]}'],
            ['update', 403, '{"errors":[{"status":"403","title":"Forbidden"}]}'],
        ];
    }
}
