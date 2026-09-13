# Refund demands

`Edge\RefundDemand` supports `list`, `show`, and `create`, returning
`Edge\ResourceResult`. There are no update, confirm, or delete methods.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test'); // Replace with your secret key.
$result = Edge\RefundDemand::create($client, [
    'attributes' => [
        'amount_cents' => 1000, 'amount_currency' => 'USD',
        'reason' => 'custom', 'reason_note' => 'Customer requested a partial refund.',
        'idempotency_key' => 'order-123-refund-1',
    ],
    'relationships' => [
        'payment_demand' => ['type' => 'payment_demands', 'id' => 'example-payment'],
    ],
    'query' => ['include' => ['payment_demand']],
]);
$refund = $result->data; // Edge\RefundDemand
$amount = $refund->amount; // Edge\Money: integer cents and currency.
$createdAt = $refund->created_at; // DateTimeImmutable when present and valid.
$payment = $refund->getRelated('payment_demand'); // Included PaymentDemand, or linkage.
$shown = Edge\RefundDemand::show($client, $refund); // Also accepts a string ID.
$page = Edge\RefundDemand::list($client, [
    'include' => ['payment_demand'], 'sort' => ['-created_at'],
    'fields' => ['refund_demands' => ['state', 'amount_cents', 'amount_currency', 'payment_demand']],
    'page' => ['size' => 25],
]);
$status = $result->status;
$state = $shown->data->state;
$links = $page->links; // Fetch further pages explicitly.
```

Create requires payment-demand linkage; it can also be an `Edge\PaymentDemand` resource.
Omit `amount_cents` to request the full remaining balance. Currency is inherited from
that payment; an explicitly supplied currency must match. Edge validates eligibility
and calculates the remaining balance, accounting for pending, processing, succeeded,
and failed refunds. The SDK does not calculate refundable amounts. Creation does not
mean the refund has succeeded; inspect its returned `state` and subsequent updates.

`idempotency_key` is an optional merchant-scoped attribute, never a header. Repeating
the same request with the same key returns the original refund; changing payment,
amount, reason, or reason note with that key is rejected by Edge.

Readable fields are `state`, `reason`, `reason_note`, `amount_cents`, `amount_currency`,
`idempotency_key`, `created_at`, and `updated_at`. `amount` uses the cents/currency pair;
the timestamps decode to `DateTimeImmutable`. Null stays null, invalid dates/incomplete
money pairs become `Unavailable::DECODING_FAILURE`, and sparse omissions use
`Unavailable::UNFETCHED`. Attributes are read-only; unknown fields, states, and reasons
are preserved. Relationships are `payment_demand` and server-derived `merchant`.
Included refunds decode as `Edge\RefundDemand`; relationship resolution stays local,
with missing included records retained as linkage.
