# Resource API

Resource classes such as `Edge\Customer` and `Edge\PaymentDemand` wrap the low-level client:
they build JSON:API envelopes for you and decode responses into typed, read-only resources.

## Overview

All resource operations require an explicit `Edge\ApiClient` and return `Edge\ResourceResult`.
The supported operation sets match the backend contract:

| Resource | list | show | create | update | confirm |
| --- | --- | --- | --- | --- | --- |
| Customer | yes | yes | yes | yes | — |
| ConsumerAddress | yes | yes | yes | yes | — |
| PaymentMethod | yes | yes | — | — | — |
| PaymentDemand | yes | yes | yes | yes | yes |
| PaymentSubscription | yes | yes | yes | yes | yes |
| RefundDemand | yes | yes | yes | — | — |
| Merchant | yes | yes | — | — | — |
| Event | yes | yes | — | — | — |
| WebhookSubscription | yes | yes | yes | yes | — |

No resource exposes delete. See the [per-resource pages](README.md#resources) for accepted attributes and
relationships. The executable [mocked workflows](../tests/ResourceWorkflowTest.php) connect
customer/address creation, payment-method lookup, payment confirmation and refund, recurring
subscriptions, and event/webhook configuration. Run them with
`vendor/bin/phpunit --filter ResourceWorkflowTest`; they make no live API requests and
simulate the responses after browser verification rather than collecting card details.

## Shared operation conventions

All operations require an explicit `ApiClient` and return `ResourceResult`. The signatures
are `list($client, $query = [])`, `show($client, $idOrResource, $query = [])`,
`create($client, $options = [])`, `update($client, $idOrResource, $options = [])`, and
`confirm($client, $idOrResource, $query = [])`. IDs must be nonempty strings or resource
instances matching the called class and its wire type; mismatches fail before HTTP.
IDs are escaped as single URL path segments.

Create/update options accept only `attributes`, `relationships`, and `query`. Relationship
values may be a resource, an `Edge\Linkage`, an explicit `['type' => '...', 'id' => '...']`
identifier (or equivalent object), an array of identifiers/resources, or null. The encoder
adds each `data` envelope, preserving to-one null and to-many empty arrays. Empty attribute
and relationship maps serialize as `{}`; use `(object) []` for nested empty object attributes
and `[]` for nested lists. Unknown attribute values pass through for server validation.

Include/sort arrays and each `fields[type]` array become comma-separated strings; strings
are preserved. Nested filters, pagination parameters, and additional query keys are kept.
Query options never become attributes. `ConfirmOperation`, for endpoints supporting it,
sends `PATCH /{type}/{id}/confirm` with matching type/ID and `attributes: {}`. Its final
argument contains query options only; use create/update for billing changes.

Subclasses declare `TYPE` (collection path), `SCHEMA` (explicit date/money mappings), and
`FIELDS` (known wire fields for sparse omission tracking). Each request uses a fresh decoder
registered for built-in types and the called class. Subclasses can override protected `resourceDecoder()` and
extend `parent::resourceDecoder()` with additional named included-type registrations.
Unregistered types remain generic resources. Pagination links and HTTP metadata are retained
without fetching more pages; errors remain `Edge\Exception` through the shared transport.


## Pagination

Pagination is explicit. Request the next page using known page parameters, or decode a
returned same-origin link while preserving the original sparse-field context:

```php
$page = Edge\Customer::list($client, ['page' => ['size' => 25]]);
$next = $page->links instanceof \stdClass ? ($page->links->next ?? null) : null;
if (is_string($next)) {
    $response = $client->requestResponse('GET', $next);
    $page = (new Edge\ResourceDecoder())->decode($response, $page->query);
}
```

No page or relationship is fetched automatically. For the example's data access, distinguish
null and `Edge\Unavailable` from a populated resource or `Money`; the value rules below
explain sparse omissions and decoding failures.

## Shared resource operations

`Edge\ApiResource` connects the instance client and resource decoder. Endpoint subclasses
opt into `Edge\Operations\ListOperation`, `ShowOperation`, `CreateOperation`,
`UpdateOperation`, and `ConfirmOperation` individually. Unsupported methods are absent.

## Resource results and local relationships

`ResourceDecoder::decode($response, $query = [])` converts an `Edge\Response` into a
read-only `Edge\ResourceResult`. Existing static and instance client methods retain their
plain-document return values. You can also decode a response explicitly using the built-in mappings:

```php
$decoder = new Edge\ResourceDecoder();
$query = ['include' => 'buyer'];
$response = $client->requestResponse('GET', 'payment_demands/example-demand', ['query' => $query]);
$result = $decoder->decode($response, $query);
$demand = $result->data;
$buyer = $demand->getRelated('buyer'); // Included Customer, or unresolved Linkage.
$originalIdentifier = $demand->buyer->data; // Original linkage, including metadata.
```

Result properties are `data` (resource, null, or resource array), `included` (resource array),
`links`, `meta`, `status` (HTTP status), `headers` (header names mapped to value arrays),
`raw` (the complete decoded document), and `query` (the supplied query context). Empty
successful bodies produce null data/raw and an empty included array; `data: []` stays an
empty collection. Absent links/meta are `Unavailable::UNDEFINED`; explicit nulls and empty
objects remain intact. Unknown document members remain in `raw`. Malformed JSON or invalid
resource identities throw `Edge\Exception`. Pagination links are retained without fetching.

The registry is local to each decoder. `register($type, $class, $schema = [], $fields = [])`
accepts a `Resource` subclass inheriting its constructor contract, explicit value mappings,
and known wire attribute/relationship names. Unregistered types use generic `Resource`
without guessed value conversions. All nine endpoint classes are registered by default. Pass the original query to preserve sparse-field context: `fields[type]`
accepts a comma-separated string or array. Registered fields and schema source fields omitted
from that selection are unfetched; selected-but-missing and unknown fields are undefined.
Returned values always take precedence over sparse omissions.

`getRelated($name)` is shared by all resources and performs only local lookup. It returns a
resolved resource, unresolved `Linkage`, a to-many array of either, explicit null, or
`Unavailable`. Empty relationships remain `[]`; links-only relationships are unfetched.
Missing relationships follow the same sparse/undefined rules as attributes. Standalone
resources have no index and return their original linkage. Attribute/relationship name
collisions can be handled through `getRelated()` and `getRelationships()`.

Primary and included records use one per-result type/ID index, so repeated identities and
cyclic relationships refer to the same objects. The first primary occurrence wins over later
primary or included duplicates; otherwise the first included occurrence wins. Records are
not merged, and every original occurrence remains in `raw`. Separate results have separate
identities. Relationship envelopes retain raw linkage, links, metadata, and extensions even
when `getRelated()` resolves their targets. Access never sends HTTP requests.

## Resource and value primitives

`Edge\Resource` represents a single JSON:API resource locally. Endpoint classes extend this foundation with supported operations
and backend field mappings. Existing client methods still return plain decoded documents.

```php
$resource = new Edge\Resource([
    'id' => 'example-demand',
    'type' => 'payment_demands',
    'attributes' => [
        'amount_cents' => 4999,
        'amount_currency' => 'USD',
        'created_at' => '2024-06-06T21:48:33.382755Z',
    ],
    'relationships' => [
        'buyer' => ['data' => ['type' => 'customers', 'id' => 'example-customer']],
    ],
], [
    'dates' => ['created_at'],
    'money' => ['amount' => ['amount_cents', 'amount_currency']],
], ['description']); // Fields known to have been excluded by sparse selection.

$resource->amount->cents;          // 4999 (integer)
$resource->amount->currency;       // 'USD'
$resource->amount_cents;           // 4999, original field remains accessible
$resource->created_at;            // DateTimeImmutable
$resource->buyer->data;            // Edge\Linkage, no HTTP request
$resource->description->reason;   // Edge\Unavailable::UNFETCHED
$resource->unknown->reason;       // Edge\Unavailable::UNDEFINED
```

`Resource($resource, $schema = [], $unfetched = [])` accepts an associative array or `stdClass`
for one resource, not a top-level document. Schemas explicitly name date fields and money
pairs; nothing is inferred from type names or field suffixes. Endpoint operations supply the [backend-established mappings](resource-contract.md#embedded-attributes-and-money).
Unknown attributes and enum strings remain unchanged. A wire attribute wins if its name
collides with a derived money property.

`id`, `type`, attributes, and relationships are read-only properties. Assignment or unset,
including unknown properties, throws `LogicException`. `getAttributes()` returns the converted
attribute map; `getRelationships()` returns the relationship map. `getLinks()` and `getMeta()`
return resource-level values. `getMember($name)` accesses any original top-level member,
including `attributes`, `relationships`, or extensions, and `getRaw()` returns the original
resource shape. These accessors preserve empty objects versus arrays. Nested JSON objects
are copied on input and output so modifying a returned object cannot alter stored values.
Use the accessors for envelope members whose names also occur as attributes.

`Money($cents, $currency)` requires an actual PHP integer and a nonempty currency string,
exposed as read-only `cents` and `currency`. Zero, negative values, and unknown currency
strings are retained exactly; invalid inputs throw `InvalidArgumentException`. There is no
floating-point conversion or arithmetic. Original money field names and values remain in
the resource's raw attributes.

`ValueDecoder::timestamp($value)` accepts null or an RFC 3339 timestamp with a timezone and
up to six fractional digits, returning null or `DateTimeImmutable`. Invalid dates, overflowed
times, and parser normalization produce an unavailable value. `ValueDecoder::money($attributes,
$centsField, $currencyField, $absentReason = Unavailable::UNDEFINED)` returns `Money` for a
complete valid pair, null for two explicit nulls, and a decoding failure for partial or invalid
pairs. If both fields are absent it uses the supplied absence reason. A resource marks absent
money as unfetched when either source field is listed in `$unfetched`; partial pairs still fail.

`Unavailable($reason, $raw = null)` exposes read-only `reason` and `raw`. Reasons are
`UNFETCHED` (known exclusion or relationship without linkage), `UNDEFINED` (missing without
evidence of exclusion), and `DECODING_FAILURE` (invalid supplied value). Failures retain their
wire input; incomplete money retains only fields actually present. Explicit JSON null stays
null. `isset()` is false for both null and unavailable properties, so inspect the property
value and its reason when the distinction matters.

`Relationship($relationship)` accepts an array or `stdClass` envelope. Its read-only `data`
is a `Linkage`, array of `Linkage`, null, or `Unavailable`; an empty collection stays `[]`,
and absent data is unfetched. Malformed linkage produces a decoding failure. Relationship
`links`, `meta`, and extensions are independent of resource-level values; `getRaw()` preserves
the original envelope. `Linkage($identifier)` requires string `type` and `id`, exposes those
and any metadata/extensions as read-only properties, and provides `getRaw()`. Linkage remains
unresolved in this part; none of these primitives fetches data automatically.

## Migrating from static calls

Migrate one call at a time; existing static calls can coexist with resource operations:

```php
// Existing code: configure global auth, build the complete envelope, read raw attributes.
Edge\Auth::setApiKey('ept_sandbox_s_test');
$document = Edge\Client::get('payment_demands/example-demand');
$cents = $document->data->attributes->amount_cents;

// Resource code: pass an isolated client and read mapped properties.
$client = new Edge\ApiClient('ept_sandbox_s_test');
$result = Edge\PaymentDemand::show($client, 'example-demand', ['include' => ['buyer']]);
$demand = $result->data;
$amount = $demand->amount; // Money when both wire fields are valid.
$buyer = $demand->getRelated('buyer');
$status = $result->status;
$requestHeaders = $result->headers;
$original = $result->raw;
```

For writes, move the old `data.attributes` and `data.relationships` into separate
`attributes` and `relationships` options. Supply relationship identifiers or resources
without the old `data` wrapper; pass query parameters under `query`. The resource class
supplies the type and envelope, and update/show accept either an ID or a matching resource.
Both interfaces continue to throw `Edge\Exception`, including its status, raw body, and
JSON:API errors. Low-level `ApiClient` calls still return raw documents, so merely replacing
`Client::get` with `$client->get` does not enable resource decoding.
