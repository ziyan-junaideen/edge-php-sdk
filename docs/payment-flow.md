# Payment flow

This page uses the static client. With the resource API, the same steps are
`Edge\PaymentDemand::create()` and `Edge\PaymentDemand::confirm()` — see
[payment demands](resources/payment-demands.md).

Creating a payment demand leaves it unconfirmed (`confirmed: false`). Your server creates it, the
browser collects the card details against it, and it is then confirmed.

```php
// Server: create the demand and send its id to the browser.
$demand = Edge\Client::create('payment_demands', [
    'data' => [
        'type' => 'payment_demands',
        'attributes' => [
            'amount_cents' => 4999,
            'amount_currency' => 'USD',
            'description' => 'Order #1234',
        ],
        'relationships' => [
            'buyer' => ['data' => ['type' => 'customers', 'id' => $customerId]],
        ],
    ],
]);

$paymentDemandId = $demand->data->id;
```

```javascript
// Browser: mount the hosted form against that demand, using the publishable key.
const edge = new Edge(publishableKey, { formFactor: 'embedded' })
edge.mountPaymentForm('payment-form', paymentDemandId)

// When the customer submits, verify the card they entered. This resolves once the
// iframe reports back, and resolves for failures too — check the event type.
const event = await edge.verifyPaymentMethod()

if (event.type !== 'payment_method_verified') {
    // 'payment_method_error', 'payment_method_failed' or 'payment_method_changed'
    throw new Error(`Card could not be verified: ${event.type}`)
}

// Only now is the payment method attached to the demand. Tell your server to confirm.
await fetch('/checkout/confirm', {
    method: 'POST',
    body: JSON.stringify({ paymentDemandId }),
})
```

```php
// Server: confirm once the browser reports the payment method verified.
Edge\Client::confirm('payment_demands', $paymentDemandId);
```

The card details never reach your server — the iframe attaches the payment method to the demand
directly, and your server only ever sees the demand id.

Treat `GET /payment_demands/{id}` as a convenience check rather than the source of truth for the
final outcome; webhooks (`webhook_subscriptions`) are authoritative. The browser can also await
`edge.listenToPayment(paymentDemandId)` for the approval event.

`confirm()` also works for `payment_subscriptions`.
