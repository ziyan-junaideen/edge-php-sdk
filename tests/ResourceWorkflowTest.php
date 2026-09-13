<?php

namespace Edge\Tests;

use Edge\ApiClient;
use Edge\ConsumerAddress;
use Edge\Customer;
use Edge\Event;
use Edge\Merchant;
use Edge\PaymentDemand;
use Edge\PaymentMethod;
use Edge\PaymentSubscription;
use Edge\RefundDemand;
use Edge\WebhookSubscription;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** Executable examples: all responses are mocked, including post-verification states. */
class ResourceWorkflowTest extends TestCase
{
    private $history = [];

    private function record($type, $id, array $attributes = [])
    {
        return ['type' => $type, 'id' => $id, 'attributes' => (object) $attributes];
    }

    private function response($record, array $included = [], $status = 200)
    {
        return new Response($status, [], json_encode(['data' => $record, 'included' => $included]));
    }

    private function client(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new ApiClient('ept_sandbox_s_test', ['http_client' => new GuzzleClient(['handler' => $stack])]);
    }

    private function body($index)
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true)['data'];
    }

    public function testCustomerAddressPaymentConfirmationAndRefundWorkflow()
    {
        $customerRaw = $this->record('customers', 'customer-1', ['name' => 'Ada']);
        $addressRaw = $this->record('consumer_addresses', 'address-1', ['line_1' => '123 Main St']);
        $methodRaw = $this->record('payment_methods', 'method-1', ['last_four' => '0001']);
        $paymentRaw = $this->record('payment_demands', 'payment-1', ['amount_cents' => 2500, 'amount_currency' => 'USD']);
        $paymentRaw['relationships'] = ['buyer' => ['data' => ['type' => 'customers', 'id' => 'customer-1']]];
        $refundRaw = $this->record('refund_demands', 'refund-1', ['amount_cents' => 1000, 'amount_currency' => 'USD']);
        $refundRaw['relationships'] = ['payment_demand' => ['data' => ['type' => 'payment_demands', 'id' => 'payment-1']]];
        $client = $this->client([
            $this->response($customerRaw, [], 201), $this->response($addressRaw, [], 201),
            $this->response($methodRaw), $this->response($paymentRaw, [$customerRaw], 201),
            $this->response($paymentRaw, [$customerRaw]), $this->response($refundRaw, [$paymentRaw, $customerRaw], 201),
        ]);
        $customer = Customer::create($client, ['attributes' => ['name' => 'Ada', 'email' => 'ada@example.com']])->data;
        $address = ConsumerAddress::create($client, [
            'attributes' => ['line_1' => '123 Main St', 'city' => 'Los Angeles', 'state' => 'CA', 'zip' => '90001', 'country' => 'USA'],
            'relationships' => ['customer' => $customer],
        ])->data;
        // A payment method previously collected and verified by the browser integration.
        $method = PaymentMethod::show($client, 'method-1')->data;
        $payment = PaymentDemand::create($client, [
            'attributes' => ['amount_cents' => 2500, 'amount_currency' => 'USD', 'confirmed' => false, 'idempotency_key' => 'order-1'],
            'relationships' => ['buyer' => $customer, 'billing_address' => $address, 'payment_method' => $method],
            'query' => ['include' => ['buyer']],
        ])->data;
        $confirmed = PaymentDemand::confirm($client, $payment)->data;
        $refund = RefundDemand::create($client, [
            'attributes' => ['amount_cents' => 1000, 'amount_currency' => 'USD', 'reason' => 'custom',
                'reason_note' => 'Partial refund requested', 'idempotency_key' => 'refund-1'],
            'relationships' => ['payment_demand' => $confirmed],
            'query' => ['include' => ['payment_demand.buyer']],
        ])->data;
        $this->assertSame(['type' => 'customers', 'id' => $customer->id], $this->body(1)['relationships']['customer']['data']);
        foreach (['buyer' => $customer, 'billing_address' => $address, 'payment_method' => $method] as $name => $resource) {
            $this->assertSame(['type' => $resource->type, 'id' => $resource->id], $this->body(3)['relationships'][$name]['data']);
        }
        $this->assertSame('/v2/payment_demands/payment-1/confirm', $this->history[4]['request']->getUri()->getPath());
        $this->assertSame('{"data":{"type":"payment_demands","id":"payment-1","attributes":{}}}', (string) $this->history[4]['request']->getBody());
        $this->assertSame('payment-1', $this->body(5)['relationships']['payment_demand']['data']['id']);
        $this->assertSame(1000, $refund->amount->cents);
        $this->assertSame(2500, $refund->getRelated('payment_demand')->amount->cents);
        $this->assertSame('Ada', $refund->getRelated('payment_demand')->getRelated('buyer')->name);
        $this->assertNotSame($customer, $payment->getRelated('buyer')); // Identity is per result.
        $this->assertCount(6, $this->history);
    }

    public function testRecurringSubscriptionEventAndWebhookConfigurationWorkflow()
    {
        $customerRaw = $this->record('customers', 'customer-1');
        $raw = $this->record('payment_subscriptions', 'subscription-1', [
            'amount_cents' => 2500, 'amount_currency' => 'USD', 'billing_period' => 'one_month',
            'billing_cycle_anchor_at' => '2027-01-01T00:00:00Z',
        ]);
        $webhookRaw = $this->record('webhook_subscriptions', 'webhook-1', ['status' => 'active']);
        $merchantRaw = $this->record('merchants', 'merchant-1');
        $eventRaw = $this->record('events', 'event-1', ['slug' => 'updated', 'resource_id' => 'subscription-1',
            'resource_type' => 'transaction.payment_subscriptions', 'data' => $raw]);
        $eventRaw['relationships'] = ['merchant' => ['data' => ['type' => 'merchants', 'id' => 'merchant-1']]];
        $client = $this->client([
            $this->response($customerRaw), $this->response($raw, [], 201), $this->response($raw), $this->response($raw),
            $this->response($webhookRaw, [], 201), $this->response($webhookRaw), $this->response($webhookRaw),
            $this->response([$eventRaw]), $this->response($eventRaw, [$merchantRaw]),
        ]);
        $customer = Customer::show($client, 'customer-1')->data;
        $subscription = PaymentSubscription::create($client, [
            'attributes' => ['amount_cents' => 2500, 'amount_currency' => 'USD', 'billing_period' => 'one_month',
                'billing_cycle_anchor_at' => '2027-01-01T00:00:00Z', 'confirmed' => false, 'idempotency_key' => 'membership-1'],
            'relationships' => ['buyer' => $customer],
        ])->data;
        $updated = PaymentSubscription::update($client, $subscription, ['attributes' => ['proration_behavior' => 'none']])->data;
        // Simulate the later server call after browser verification has completed.
        $confirmed = PaymentSubscription::confirm($client, $updated)->data;
        $webhook = WebhookSubscription::create($client, ['attributes' => [
            'mode' => 'sandbox', 'description' => 'Subscription notifications', 'concurrency_limit' => 5,
            'events' => ['transaction.payment_subscriptions.updated'], 'url' => 'https://example.com/webhooks/edge',
        ]])->data;
        WebhookSubscription::update($client, $webhook, ['attributes' => ['description' => 'Updated notifications']]);
        $shown = WebhookSubscription::show($client, $webhook)->data;
        $events = Event::list($client, ['filter' => ['resource_id' => $confirmed->id]])->data;
        $event = Event::show($client, $events[0], ['include' => ['merchant']])->data;
        $this->assertSame('customer-1', $this->body(1)['relationships']['buyer']['data']['id']);
        $this->assertSame(['proration_behavior' => 'none'], $this->body(2)['attributes']);
        $this->assertSame('{"data":{"type":"payment_subscriptions","id":"subscription-1","attributes":{}}}', (string) $this->history[3]['request']->getBody());
        $this->assertInstanceOf(\DateTimeImmutable::class, $confirmed->billing_cycle_anchor_at);
        $this->assertSame(2500, $confirmed->amount->cents);
        $this->assertSame(['transaction.payment_subscriptions.updated'], $this->body(4)['attributes']['events']);
        $this->assertSame($webhook->id, $shown->id);
        $this->assertInstanceOf(Merchant::class, $event->getRelated('merchant'));
        $this->assertSame($confirmed->id, $event->resource_id);
        $this->assertInstanceOf(\stdClass::class, $event->data);
        $this->assertSame('2027-01-01T00:00:00Z', $event->data->attributes->billing_cycle_anchor_at);
        $this->assertCount(9, $this->history);
    }
}
