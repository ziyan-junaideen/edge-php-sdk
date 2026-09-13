<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\ConsumerAddress;
use Edge\Customer;
use Edge\Exception;
use Edge\Linkage;
use Edge\Money;
use Edge\PaymentSubscription;
use Edge\PaymentMethod;
use Edge\ResourceDecoder;
use Edge\ResourceResult;
use Edge\Unavailable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class PaymentSubscriptionTest extends TestCase
{
    private $history = [];

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ApiClient('ept_sandbox_s_test', [
            'http_client' => new GuzzleClient(['handler' => $stack]),
            'user_agent_suffix' => 'SubscriptionTest/1.0',
        ]);
    }

    /** @dataProvider operations */
    public function testOperationsUseExactRoutesBodiesAndQueryParameters($operation, $useResource)
    {
        $id = 'a/b ?#%';
        $raw = ['type' => 'payment_subscriptions', 'id' => $id];
        $status = $operation === 'create' ? 201 : 200;
        $client = $this->client([new Response($status, [], json_encode(['data' => $operation === 'list' ? [$raw] : $raw]))]);
        $query = [
            'include' => ['buyer', 'payment_method'], 'sort' => ['-created_at', 'description'],
            'fields' => ['payment_subscriptions' => ['amount_cents', 'amount_currency', 'buyer'], 'customers' => 'name'],
            'filter' => ['buyer' => ['email' => 'ada@example.com']], 'page' => ['size' => 25, 'number' => 2],
        ];
        $args = [$client];
        if (in_array($operation, ['show', 'update', 'confirm'], true)) {
            $args[] = $useResource ? new PaymentSubscription($raw) : $id;
        }
        $expected = ['type' => 'payment_subscriptions'];
        if (in_array($operation, ['update', 'confirm'], true)) {
            $expected['id'] = $id;
        }
        $expected['attributes'] = new \stdClass();
        if (in_array($operation, ['create', 'update'], true)) {
            $attributes = $operation === 'create' ? [
                'amount_cents' => 2500, 'amount_currency' => 'USD', 'confirmed' => false,
                'idempotency_key' => 'order-test-1', 'billing_period' => 'one_month', 'billing_cycle_anchor_at' => '2027-01-01T00:00:00Z', 'proration_behavior' => 'create_prorations',
                'line_items' => [['amount_cents' => 2500, 'amount_currency' => 'USD', 'quantity' => 1]],
            ] : ['billing_period' => 'seven_days', 'billing_cycle_anchor_at' => '2027-02-01T00:00:00+05:30', 'proration_behavior' => 'none'];
            $relationships = [
                'buyer' => new Customer(['type' => 'customers', 'id' => 'buyer-1']),
                'payer' => ['type' => 'customers', 'id' => 'payer-1'],
                'receiver' => new Linkage(['type' => 'customers', 'id' => 'receiver-1']),
                'billing_address' => new ConsumerAddress(['type' => 'consumer_addresses', 'id' => 'address-1']),
                'shipping_address' => null,
            ];
            $encoded = [
                'buyer' => ['data' => ['type' => 'customers', 'id' => 'buyer-1']],
                'payer' => ['data' => ['type' => 'customers', 'id' => 'payer-1']],
                'receiver' => ['data' => ['type' => 'customers', 'id' => 'receiver-1']],
                'billing_address' => ['data' => ['type' => 'consumer_addresses', 'id' => 'address-1']],
                'shipping_address' => ['data' => null],
            ];
            if ($operation === 'create') {
                $relationships['payment_method'] = new PaymentMethod(['type' => 'payment_methods', 'id' => 'method-1']);
                $encoded['payment_method'] = ['data' => ['type' => 'payment_methods', 'id' => 'method-1']];
            }
            $expected['attributes'] = (object) $attributes;
            $expected['relationships'] = (object) $encoded;
            $args[] = ['attributes' => $attributes, 'relationships' => $relationships, 'query' => $query];
        } else {
            $args[] = $query;
        }
        $result = PaymentSubscription::$operation(...$args);
        $request = $this->history[0]['request'];
        $expectedQuery = $query;
        $expectedQuery['include'] = 'buyer,payment_method';
        $expectedQuery['sort'] = '-created_at,description';
        $expectedQuery['fields']['payment_subscriptions'] = 'amount_cents,amount_currency,buyer';
        parse_str($request->getUri()->getQuery(), $sent);
        $this->assertEquals($expectedQuery, $sent);
        $this->assertSame($expectedQuery, $result->query);
        $path = '/v2/payment_subscriptions' . (in_array($operation, ['show', 'update', 'confirm'], true) ? '/a%2Fb%20%3F%23%25' : '');
        $this->assertSame($path . ($operation === 'confirm' ? '/confirm' : ''), $request->getUri()->getPath());
        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('api.tryedge.io', $request->getUri()->getHost());
        $this->assertSame(['list' => 'GET', 'show' => 'GET', 'create' => 'POST', 'update' => 'PATCH', 'confirm' => 'PATCH'][$operation], $request->getMethod());
        $this->assertSame('Bearer ept_sandbox_s_test', $request->getHeaderLine('Authorization'));
        $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('SubscriptionTest/1.0', $request->getHeaderLine('User-Agent'));
        $this->assertFalse($request->hasHeader('Idempotency-Key'));
        $this->assertTrue($this->history[0]['options']['verify']);
        if (in_array($operation, ['list', 'show'], true)) {
            $this->assertSame('', (string) $request->getBody());
        } else {
            $this->assertSame(ApiClient::MEDIA_TYPE, $request->getHeaderLine('Content-Type'));
            $this->assertSame(json_encode(['data' => $expected]), (string) $request->getBody());
        }
        $this->assertInstanceOf(ResourceResult::class, $result);
        $this->assertInstanceOf(PaymentSubscription::class, $operation === 'list' ? $result->data[0] : $result->data);
        $this->assertSame($status, $result->status);
    }

    public function operations()
    {
        return [['list', false], ['show', false], ['show', true], ['create', false],
            ['update', false], ['update', true], ['confirm', false], ['confirm', true]];
    }

    public function testRichValuesEmbeddedDataAndRelationshipsArePreserved()
    {
        $attributes = [
            'amount_cents' => 2500, 'amount_currency' => 'USD', 'fee_cents' => 75, 'discount_cents' => 100,
            'created_at' => '2026-09-13T10:00:00.123456Z', 'updated_at' => '2026-09-13T12:00:00+02:00',
            'canceled_at' => '2026-09-13T10:01:00Z', 'status' => 'future_state',
            'billing_period' => 'future_period', 'proration_behavior' => 'future_proration', 'billing_scheme' => 'future_scheme', 'purchase_kind' => 'future_kind', 'confirmed' => true,
            'email_receipt' => false, 'threeds_status' => 'future_status', 'cvc2_check' => 'future_check',
            'description' => null, 'idempotency_key' => 'test-order', 'future_field' => true,
            'line_items' => [['amount_cents' => 2500, 'amount_currency' => 'USD', 'quantity' => 1, 'extra' => 'kept']],
            'shipping_detail' => ['shipping_cents' => 0, 'shipping_currency' => 'USD'],
            'tax_detail' => ['tax_cents' => 100, 'tax_currency' => 'USD', 'id' => 'embedded-id'],
        ];
        foreach (['billing_cycle_anchor_at', 'trial_end_at', 'next_billing_at',
            'current_period_start_at', 'current_period_end_at', 'last_processed_at',
            'last_active_at', 'last_paused_at'] as $field) {
            $attributes[$field] = '2027-01-01T00:00:00+05:30';
        }
        $attributes['alert_receipt'] = true;
        $attributes['canceled_at_period_end'] = false;
        $attributes['subscription_notification_email'] = 'ada@example.com';
        $attributes['slug'] = 'membership';
        $relationships = [];
        $included = [];
        foreach (['buyer' => 'customers', 'payer' => 'customers', 'receiver' => 'customers',
            'payment_method' => 'payment_methods', 'billing_address' => 'consumer_addresses'] as $name => $type) {
            $relationships[$name] = ['data' => ['type' => $type, 'id' => $name]];
            $included[] = ['type' => $type, 'id' => $name];
        }
        $relationships['shipping_address'] = ['data' => null];
        $relationships['merchant'] = ['data' => ['type' => 'merchants', 'id' => 'merchant-1']];
        $raw = ['type' => 'payment_subscriptions', 'id' => '1', 'attributes' => $attributes,
            'relationships' => $relationships, 'meta' => ['example' => true], 'links' => (object) [], 'extension' => true];
        $document = ['data' => $raw, 'included' => $included, 'meta' => ['example' => true], 'links' => ['self' => '/v2/payment_subscriptions/1']];
        $client = $this->client([new Response(200, ['X-Request-Id' => 'subscription-request'], json_encode($document))]);
        $result = PaymentSubscription::show($client, '1');
        $subscription = $result->data;
        $this->assertEquals(new Money(2500, 'USD'), $subscription->amount);
        $this->assertEquals(new Money(75, 'USD'), $subscription->fee);
        foreach (PaymentSubscription::SCHEMA['dates'] as $field) {
            $this->assertInstanceOf(\DateTimeImmutable::class, $subscription->$field);
        }
        $this->assertSame('123456', $subscription->created_at->format('u'));
        foreach (array_diff(array_keys($attributes), PaymentSubscription::SCHEMA['dates']) as $field) {
            $this->assertEquals(json_decode(json_encode($attributes[$field])), $subscription->$field);
        }
        foreach (['discount', 'total_collected', 'amount_refunded', 'fee_currency', 'capture_method', 'payment_demands'] as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNDEFINED), $subscription->$field);
        }
        foreach (['buyer', 'payer', 'receiver'] as $name) {
            $this->assertInstanceOf(Customer::class, $subscription->getRelated($name));
        }
        $this->assertInstanceOf(PaymentMethod::class, $subscription->getRelated('payment_method'));
        $this->assertInstanceOf(ConsumerAddress::class, $subscription->getRelated('billing_address'));
        $this->assertNull($subscription->getRelated('shipping_address'));
        $this->assertInstanceOf(Linkage::class, $subscription->getRelated('merchant'));
        $this->assertEquals(json_decode(json_encode($raw)), $subscription->getRaw());
        $this->assertEquals(json_decode(json_encode($document)), $result->raw);
        $this->assertTrue($result->meta->example);
        $this->assertSame('/v2/payment_subscriptions/1', $result->links->self);
        $this->assertSame(['subscription-request'], $result->headers['X-Request-Id']);
        $this->assertCount(1, $this->history);
        $this->expectException(\LogicException::class);
        $subscription->status = 'succeeded';
    }

    public function testIncludedSubscriptionsUseMoneyDatesSparseFieldsAndLocalOverrides()
    {
        $response = new \Edge\Response(new Response(200, [], json_encode([
            'data' => ['type' => 'payment_demands', 'id' => '1', 'relationships' => [
                'payment_subscription' => ['data' => ['type' => 'payment_subscriptions', 'id' => '2']],
            ]],
            'included' => [['type' => 'payment_subscriptions', 'id' => '2', 'attributes' => [
                'amount_cents' => 2500, 'amount_currency' => 'USD', 'fee_cents' => 75, 'created_at' => '2026-09-13T10:00:00Z',
            ]]],
        ])));
        $selected = ['amount_cents', 'amount_currency', 'fee_cents', 'created_at'];
        $result = (new ResourceDecoder())->decode($response, ['fields' => ['payment_subscriptions' => $selected]]);
        $subscription = $result->data->getRelated('payment_subscription');
        $this->assertInstanceOf(PaymentSubscription::class, $subscription);
        $this->assertSame($result->included[0], $subscription);
        $this->assertEquals(new Money(2500, 'USD'), $subscription->amount);
        $this->assertEquals(new Money(75, 'USD'), $subscription->fee);
        $this->assertInstanceOf(\DateTimeImmutable::class, $subscription->created_at);
        foreach (array_diff(PaymentSubscription::FIELDS, $selected) as $field) {
            $this->assertEquals(new Unavailable(Unavailable::UNFETCHED), $subscription->$field);
        }
        $custom = (new ResourceDecoder())->register('payment_subscriptions', DecodedResource::class)->decode($response);
        $this->assertInstanceOf(DecodedResource::class, $custom->included[0]);
        $this->assertInstanceOf(PaymentSubscription::class, (new ResourceResult($response))->included[0]);
    }

    public function testMoneyDateFailuresNullsAndSparseOmissionsRemainDistinct()
    {
        $records = [
            ['amount_cents' => 100, 'created_at' => 'invalid'],
            ['amount_cents' => null, 'amount_currency' => null, 'fee_cents' => null, 'canceled_at' => null],
            ['amount_cents' => '100', 'amount_currency' => 'USD', 'fee_cents' => 5],
            [],
        ];
        $client = $this->client(array_map(function ($attributes) {
            return new Response(200, [], json_encode(['data' => ['type' => 'payment_subscriptions', 'id' => '1', 'attributes' => (object) $attributes]]));
        }, $records));
        $first = PaymentSubscription::show($client, '1')->data;
        $this->assertSame(Unavailable::DECODING_FAILURE, $first->amount->reason);
        $this->assertSame(Unavailable::DECODING_FAILURE, $first->created_at->reason);
        $second = PaymentSubscription::show($client, '1')->data;
        $this->assertNull($second->amount);
        $this->assertNull($second->fee);
        $this->assertNull($second->canceled_at);
        $third = PaymentSubscription::show($client, '1')->data;
        $this->assertSame(Unavailable::DECODING_FAILURE, $third->amount->reason);
        $this->assertEquals(new Money(5, 'USD'), $third->fee);
        $fourth = PaymentSubscription::show($client, '1', ['fields' => ['payment_subscriptions' => 'description']])->data;
        $this->assertSame(Unavailable::UNFETCHED, $fourth->amount->reason);
        $this->assertSame(Unavailable::UNFETCHED, $fourth->fee->reason);
        $this->assertSame(Unavailable::UNDEFINED, $fourth->description->reason);
    }

    public function testEmptyResultsPaginationAndMismatchedIdentities()
    {
        $client = $this->client([new Response(200, [], '{"data":[],"links":{"next":"https://api.tryedge.io/v2/payment_subscriptions?page[number]=2"}}'), new Response(204)]);
        $page = PaymentSubscription::list($client);
        $this->assertSame([], $page->data);
        $this->assertSame('https://api.tryedge.io/v2/payment_subscriptions?page[number]=2', $page->links->next);
        $this->assertCount(1, $this->history);
        $this->assertNull(PaymentSubscription::confirm($client, '1')->data);
        foreach (['show', 'update', 'confirm'] as $operation) {
            foreach ([new Customer(['type' => 'payment_subscriptions', 'id' => '1']), new PaymentSubscription(['type' => 'customers', 'id' => '1'])] as $resource) {
                try {
                    PaymentSubscription::$operation($client, $resource);
                    $this->fail('Expected mismatched resource rejection.');
                } catch (\InvalidArgumentException $e) {
                    $this->assertCount(2, $this->history);
                }
            }
        }
        $this->assertFalse(method_exists(PaymentSubscription::class, 'delete'));
        $this->assertFalse(method_exists(PaymentSubscription::class, 'cancel'));
    }

    /** @dataProvider failures */
    public function testApiFailuresKeepStatusBodyAndErrorDetails($operation, $status, $body)
    {
        $client = $this->client([new Response($status, [], $body)]);
        $args = [$client];
        if (in_array($operation, ['show', 'update', 'confirm'], true)) {
            $args[] = '1';
        }
        try {
            PaymentSubscription::$operation(...$args);
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
            ['create', 422, '{"errors":[{"status":"422","detail":"Invalid amount","source":{"pointer":"/data/attributes/amount_cents"}}]}'],
            ['update', 403, 'Forbidden'], ['confirm', 405, '{"errors":[{"status":"405","title":"Method not allowed"}]}']];
    }
}
