# Payment subscriptions

`Edge\PaymentSubscription` supports `list`, `show`, `create`, `update`, and `confirm`,
returning `Edge\ResourceResult`. There are no cancel or delete methods.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test'); // Replace with your secret key.
$result = Edge\PaymentSubscription::create($client, [
    'attributes' => [
        'amount_cents' => 2500, 'amount_currency' => 'USD',
        'idempotency_key' => 'membership-123-attempt-1', 'confirmed' => false,
        'billing_period' => 'one_month',
        'billing_cycle_anchor_at' => '2027-01-01T00:00:00Z',
        'proration_behavior' => 'create_prorations',
        'email_receipt' => true, 'alert_receipt' => true,
    ],
    'relationships' => ['buyer' => ['type' => 'customers', 'id' => 'example-customer']],
    'query' => ['include' => ['buyer']],
]);
$subscription = $result->data; // Edge\PaymentSubscription
$amount = $subscription->amount; // Edge\Money.
$anchor = $subscription->billing_cycle_anchor_at; // DateTimeImmutable when present and valid.

// Billing changes are supported while the payment intent is incomplete or ready.
$updated = Edge\PaymentSubscription::update($client, $subscription, [
    'attributes' => ['billing_period' => 'one_month', 'proration_behavior' => 'none'],
]);
// In a later server request, after browser payment-method verification completes:
$confirmed = Edge\PaymentSubscription::confirm($client, $subscription, ['include' => ['buyer']]);
$shown = Edge\PaymentSubscription::show($client, $subscription->id);
$page = Edge\PaymentSubscription::list($client, [
    'sort' => ['-created_at'], 'page' => ['size' => 25],
    'fields' => ['payment_subscriptions' => ['status', 'billing_period', 'next_billing_at']],
]);
$status = $confirmed->status;
$buyer = $confirmed->data->getRelated('buyer');
$links = $page->links; // Fetch further pages explicitly.
```

Configure an anchor appropriate for your billing schedule. With a future anchor,
`create_prorations` makes Edge charge the prorated first cycle immediately and preserve
that anchor for the first full recurring cycle; `none` defers the first full charge to
the anchor. The SDK sends the supplied anchor unchanged and calculates neither charges
nor schedules. Billing period codes include `one_day`, `seven_days`, `fourteen_days`,
`thirty_days`, `one_month`, `six_months`, and `twelve_months`; unfamiliar values pass
through for server validation.

Confirmation takes query options only and sends matching type/ID with `attributes: {}`
to `PATCH /payment_subscriptions/{id}/confirm`. It realizes an eligible intent or retries
an eligible last payment of an active subscription. It does not edit billing values or
unconditionally charge an existing subscription. Use the browser integration for payment-method
verification, then confirm on the server. `idempotency_key` belongs in create attributes.

Update supports `slug`, `billing_cycle_anchor_at`, `billing_period`,
`canceled_at_period_end`, `trial_end_at`, `proration_behavior`,
`subscription_notification_email`, and `email_receipt`, on incomplete/ready intents.
The backend update does not cast amount, description, addendum fields, or `alert_receipt`;
`billing_scheme` is readable with a server default of `per_unit`, but is not cast on writes.

`amount` and `fee` map their cents fields to `amount_currency`; `discount_cents` remains
an integer, and embedded line items, tax, and shipping data stay intact. Dates are
`created_at`, `updated_at`, `billing_cycle_anchor_at`, `trial_end_at`, `next_billing_at`,
`current_period_start_at`, `current_period_end_at`, `last_processed_at`, `last_active_at`,
`last_paused_at`, and `canceled_at`. These decode to `DateTimeImmutable`, including
`canceled_at` despite its string declaration in the backend view. Explicit null stays
null, invalid dates/incomplete money pairs become `Unavailable::DECODING_FAILURE`,
and sparse omissions use `Unavailable::UNFETCHED`. Unknown fields and enum strings
are preserved. See the [resource contract](../resource-contract.md) for all readable fields.

Relationships are `merchant`, `buyer`, `payer`, `receiver`, `payment_method`,
`billing_address`, and `shipping_address`. Included subscriptions use the named class,
including PaymentDemand's `payment_subscription`. Resolution stays local. Merchant is
server-managed; customer and address linkage are supported on create/update, while
payment-method reassignment and clearing null relationships have the same backend
update limitations as PaymentDemand.
