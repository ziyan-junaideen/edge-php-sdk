# Webhook subscriptions

`Edge\WebhookSubscription` supports `list`, `show`, `create`, and `update`, returning
`Edge\ResourceResult`.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test');
$result = Edge\WebhookSubscription::create($client, [
    'attributes' => [
        'mode' => 'sandbox',
        'url' => 'https://example.com/webhooks/edge',
        'description' => 'Payment notifications',
        'concurrency_limit' => 5,
        'events' => ['transaction.payment_demands.succeeded'],
    ],
    'query' => ['include' => ['merchant']],
]);
$subscription = $result->data; // Edge\WebhookSubscription.
$merchant = $subscription->getRelated('merchant'); // Resolved locally when included.

$updated = Edge\WebhookSubscription::update($client, $subscription, [
    'attributes' => ['description' => 'Updated notifications', 'concurrency_limit' => 10],
]);
$shown = Edge\WebhookSubscription::show($client, $subscription->id);
$page = Edge\WebhookSubscription::list($client, ['sort' => ['-created_at']]);
$subscriptions = $page->data;

$archived = Edge\WebhookSubscription::update($client, $subscription, [
    'attributes' => ['status' => 'archived'],
]);
```

Create/update configuration fields are `mode`, `concurrency_limit`, `description`,
`events` (an array of event-code strings), and `url`. Edge generates `secret_key` and
assigns the merchant. No relationship writes are supported by the backend. Archiving
uses `update` with `status: archived`; this takes precedence over other attributes in
the same request. The list endpoint returns active subscriptions. There is no delete
or confirm operation.

Readable fields are `status`, `mode`, `concurrency_limit`, `description`, `events`,
`secret_key`, `url`, `archived_at`, `created_at`, and `updated_at`. The three timestamps
decode to `DateTimeImmutable`; there are no money mappings. Attributes are read-only;
unknown fields, statuses, modes, and event codes are preserved. Null stays null, invalid
dates become `Unavailable::DECODING_FAILURE`, and sparse omissions use
`Unavailable::UNFETCHED`.

Included subscriptions decode as `Edge\WebhookSubscription`. The `merchant` relationship
resolves to an included `Edge\Merchant`; `webhook_deliveries` resolves to generic
`Edge\Resource` objects. Missing included records remain linkage, and relationship
access never performs HTTP. This resource manages subscription configuration; it does
not implement signature verification, delivery, or an inbound webhook receiver.
