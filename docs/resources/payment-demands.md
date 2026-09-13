# Payment demands

`Edge\PaymentDemand` supports `list`, `show`, `create`, `update`, and `confirm`,
returning `Edge\ResourceResult`. It has no delete operation.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test'); // Replace with your secret key.
$result = Edge\PaymentDemand::create($client, [
    'attributes' => [
        'amount_cents' => 2500, 'amount_currency' => 'USD',
        'description' => 'Order 123', 'idempotency_key' => 'order-123-attempt-1',
        'confirmed' => false,
    ],
    'relationships' => ['buyer' => ['type' => 'customers', 'id' => 'example-customer']],
    'query' => ['include' => ['buyer']],
]);
$demand = $result->data; // Edge\PaymentDemand
$amount = $demand->amount; // Edge\Money: cents and currency.
$createdAt = $demand->created_at; // DateTimeImmutable when present and valid.
$buyer = $demand->getRelated('buyer'); // Included Customer, or unresolved Linkage.

$updated = Edge\PaymentDemand::update($client, $demand, [
    'attributes' => ['description' => 'Order 123 — revised description'],
]);

// In a later server request, after the browser verification step has completed:
$confirmed = Edge\PaymentDemand::confirm($client, $demand, ['include' => ['buyer']]);
$shown = Edge\PaymentDemand::show($client, $demand->id);
$page = Edge\PaymentDemand::list($client, [
    'sort' => ['-created_at'], 'page' => ['size' => 25],
    'fields' => ['payment_demands' => ['amount_cents', 'amount_currency', 'processor_state']],
]);
$status = $confirmed->status;
$links = $page->links; // Fetch additional pages explicitly.
```

Create an unconfirmed payment on the server, then use the Edge browser integration
for card collection and payment-method verification against the returned payment ID.
After verification completes, confirm from the server using the secret-key client.
The SDK does not perform browser verification or implement payment-state transitions;
Edge decides whether the payment is eligible for processing or retry. A successful
confirmation response is not a guarantee that processing has succeeded. The
[payment flow](../payment-flow.md) shows the browser side.

`idempotency_key` is an attribute, never a header. Confirmation accepts query options
only and sends `PATCH /payment_demands/{id}/confirm` with matching type/ID and
`attributes: {}`. Set supported billing values through create/update beforehand.

`amount` maps `amount_cents` with `amount_currency`; `fee` maps `fee_cents` with
that same currency. Raw wire fields remain available. `discount_cents` stays an integer;
`line_items`, `shipping_detail`, and `tax_detail` retain their nested data and unknown keys.
`created_at`, `updated_at`, and `succeeded_at` decode to `DateTimeImmutable`. Explicit
null stays null; invalid dates or incomplete money pairs become
`Unavailable::DECODING_FAILURE`. Sparse omissions use `Unavailable::UNFETCHED`.
Unknown fields and enum strings are preserved, including unfamiliar `processor_state`,
`capture_method`, 3DS, and AVS values. See the [resource contract](../resource-contract.md)
for the complete readable field list.

Relationships are `merchant`, `buyer`, `payer`, `receiver`, `payment_subscription`,
`payment_method`, `billing_address`, and `shipping_address`. Included demands decode as
`Edge\PaymentDemand`; customer, payment-method, and address records use their named
classes. Relationship resolution is local. Merchant and subscription are server-managed.
Create accepts customer, payment-method, and address linkage; update supports customer
and address linkage, but the backend update changeset omits payment-method reassignment.
Null linkage is preserved by the encoder, although the backend does not establish
clearing these relationships through update.
