# Events

`Edge\Event` supports only `list` and `show`, returning `Edge\ResourceResult`.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test');
$page = Edge\Event::list($client, [
    'sort' => ['-created_at'],
    'fields' => ['events' => ['slug', 'resource_type', 'resource_id', 'created_at']],
]);
$events = $page->data; // Array of Edge\Event objects.

$result = Edge\Event::show($client, 'example-event', ['include' => ['merchant']]);
$event = $result->data;
$slug = $event->slug;
$payload = $event->data; // Opaque decoded JSON, not an SDK resource.
$createdAt = $event->created_at; // DateTimeImmutable, null, or Edge\Unavailable.
$merchant = $event->getRelated('merchant'); // Edge\Merchant when included; otherwise linkage.
$refreshed = Edge\Event::show($client, $event); // Also accepts a matching resource.
```

Readable attributes are `data`, `mode`, `resource_id`, `resource_type`, `slug`, and
`created_at`; the only exposed relationship is `merchant`. Unfamiliar slugs, modes,
resource types, and unknown fields are preserved. `data` retains nested JSON objects,
arrays, scalars, and nulls without decoding embedded resource shapes, timestamps, or
money pairs. Only the event's `created_at` is mapped to `DateTimeImmutable`; Event has
no `updated_at` or money mapping. Explicit null stays null, invalid creation dates
become `Unavailable::DECODING_FAILURE`, and sparse omissions use `Unavailable::UNFETCHED`.

Included events decode as `Edge\Event`. Attributes are read-only, and relationship
resolution stays local. Event exposes no write operations or webhook processing.

Backend versions matching the original contract snapshot have an event-list defect: the
index action queries customers. The backend follow-up changes that query to merchant-scoped
events and adds endpoint regression tests; deploy that fix before relying on event listing.
The SDK uses the declared `GET /v2/events` route. See the [resource contract](../resource-contract.md).
