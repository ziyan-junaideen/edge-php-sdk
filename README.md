# Edge PHP SDK

A lightweight PHP SDK for the Edge payment gateway. It uses Guzzle for making API requests and
returns decoded responses. The SDK talks to `https://api.tryedge.io/v2/`, which is a
[JSON:API 1.0](https://jsonapi.org/) service speaking `application/vnd.api+json`.

## Installation

To install the SDK, add the following to your composer.json file:

```json
"require": {
    "edge-payment-technologies/edge-php-sdk": "^2.0"
}
```

Then run `composer install` to add the SDK to your project.

Version 2 targets the Edge v2 API and is not compatible with 1.x, which spoke to a host that no
longer exists. To track the development branch instead:

```json
"require": {
    "edge-payment-technologies/edge-php-sdk": "dev-main"
}
```

## API keys

Edge issues two kinds of key, and only one of them belongs in this SDK.

| Key | Looks like | Where it goes |
| --- | --- | --- |
| Secret | `ept_live_s…` / `ept_sandbox_s…` | This SDK, server side. Never expose it to a browser. |
| Publishable | `ept_live_b…` / `ept_sandbox_b…` | The browser, where the Edge JS SDK uses it to mount the hosted payment form. |

The prefix selects live or sandbox by itself — the URL is the same for both, so you switch
environments by switching keys.

Set the secret key once, before making any requests:

```php
Edge\Auth::setApiKey('ept_sandbox_s...');
```

The publishable key is never given to this SDK. Store it alongside your secret key and hand it to
the browser, where it is used with a payment demand id created by this SDK — see
[The payment flow](#the-payment-flow) below.

### Pointing at another environment

By default the SDK talks to production. Override it for local development, either in code or
through the `EDGE_API_BASE_URI` environment variable:

```php
Edge\Client::setBaseUri('https://api.tryedge.test:4001');
Edge\Client::setVerifySsl(false); // only for a local, self-signed certificate
```

You can also identify your application in the `User-Agent`:

```php
Edge\Client::setUserAgentSuffix('WooCommerce/9.1.2');
```

## Instance clients

`Edge\ApiClient` keeps credentials and configuration on each instance, so applications can
use multiple accounts or environments without changing the static client:

```php
$client = new Edge\ApiClient('ept_sandbox_s_test', [
    'user_agent_suffix' => 'ExampleApp/1.0',
]);

$customers = $client->get('customers', [
    'filter' => ['email' => 'ada@example.com'],
    'page' => ['size' => 25],
]);
$customerId = $customers->data[0]->id;
```

Constructor options:

| Option | Default | Behavior |
| --- | --- | --- |
| `base_uri` | `https://api.tryedge.io/v2/` | A bare host gains `/v2/`; an explicit path is retained. |
| `verify` | `true` | TLS verification; also accepts a CA bundle path or `false` for local self-signed TLS. |
| `user_agent_suffix` | `''` | Application identity appended to the SDK User-Agent. |
| `http_client` | A new Guzzle client | Inject a `GuzzleHttp\Client` for custom middleware or tests. |

Keys and user-agent suffixes are trimmed. Instance clients do not read `Edge\Auth`, static
`Client` settings, or `EDGE_API_BASE_URI`; pass `base_uri` explicitly for another environment.
Changing or resetting the static client does not affect existing instances.

The instance methods `get`, `create`, `update`, `patch`, and `confirm` take the same arguments
and return the same decoded JSON:API documents as their static counterparts below. Writes
still take a complete JSON:API document. Empty response bodies decode to `null`; failures
throw `Edge\Exception`. Same-origin endpoint restrictions apply to both interfaces.

Concrete endpoint classes are planned separately. Internally, `ApiClient::requestResponse()` returns
an `Edge\Response` for a single request, retaining status, headers, and the raw body for
resource decoding without storing mutable last-response state on the client.

## Resource results and local relationships

`ResourceDecoder::decode($response, $query = [])` converts an `Edge\Response` into a
read-only `Edge\ResourceResult`. Existing static and instance client methods retain their
plain-document return values. Until endpoint classes are added, decode explicitly:

```php
$decoder = new Edge\ResourceDecoder();
$decoder->register('payment_demands', Edge\Resource::class, [
    'dates' => ['created_at', 'updated_at', 'succeeded_at'],
    'money' => [
        'amount' => ['amount_cents', 'amount_currency'],
        'fee' => ['fee_cents', 'amount_currency'],
    ],
], ['description', 'buyer']);

$query = ['include' => 'buyer'];
$response = $client->requestResponse('GET', 'payment_demands/example-demand', ['query' => $query]);
$result = $decoder->decode($response, $query);
$demand = $result->data;
$buyer = $demand->getRelated('buyer'); // Included Resource, or unresolved Linkage.
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
without guessed value conversions. Concrete resource registrations will arrive with the
endpoint classes. Pass the original query to preserve sparse-field context: `fields[type]`
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

`Edge\Resource` represents a single JSON:API resource locally. These primitives are available
now, along with document decoding and local included-resource resolution. Endpoint classes
follow in later parts. Existing client methods still return plain decoded documents.

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
pairs; nothing is inferred from type names or field suffixes. Later endpoint classes will
supply the [backend-established mappings](docs/resource-contract.md#embedded-attributes-and-money).
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

## Usage

The `Client` class makes requests to the Edge API. Endpoints are relative to the API root, so
`customers` becomes `https://api.tryedge.io/v2/customers`.

| Method | Verb | Purpose |
| --- | --- | --- |
| `Client::get($endpoint, $query)` | `GET` | Fetch a resource or a collection |
| `Client::create($endpoint, $document)` | `POST` | Create a resource |
| `Client::update($endpoint, $document)` | `PATCH` | Update a resource (`Client::patch()` is an alias) |
| `Client::confirm($type, $id)` | `PATCH` | Confirm a payment demand or subscription |

The API exposes no `PUT` and no `DELETE`, so this SDK has no `delete()` method. Endpoints must be
relative; an absolute URL is accepted only when it points at the configured API, since every
request carries your secret key.

Requests take a JSON:API document — a `data` object with a `type`, and `attributes` and
`relationships` as needed:

```php
$customer = Edge\Client::create('customers', [
    'data' => [
        'type' => 'customers',
        'attributes' => [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ],
    ],
]);

$customerId = $customer->data->id;
```

Responses are decoded into plain objects, so you walk the JSON:API document directly:

```php
$demand = Edge\Client::get('payment_demands/' . $paymentDemandId);

$demand->data->id;
$demand->data->attributes->processor_state;
```

### Querying collections

The second argument to `get()` becomes the query string and accepts the standard JSON:API
parameters — `filter[…]`, `include`, `fields[…]`, `sort` and `page[…]`:

```php
$customers = Edge\Client::get('customers', [
    'filter' => ['email' => 'ada@example.com'],
    'include' => 'addresses',
    'page' => ['size' => 25, 'number' => 1],
]);

$firstId = $customers->data[0]->id;
```

### The payment flow

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

### Idempotency

Idempotency is an attribute, not a header. Set `idempotency_key` on the document and repeating a
create on `payment_demands` or `payment_subscriptions` returns the existing record:

```php
Edge\Client::create('payment_demands', [
    'data' => [
        'type' => 'payment_demands',
        'attributes' => [
            'idempotency_key' => 'order-1234',
            // ...
        ],
    ],
]);
```

## Error Handling

Failed requests throw an `Edge\Exception`:

```php
try {
    $response = Edge\Client::create('payment_demands', $document);
} catch (Edge\Exception $e) {
    $e->getMessage();     // "amount_cents must be greater than 0"
    $e->getStatusCode();  // 422
    $e->getErrors();      // [['status' => '422', 'detail' => '...', 'source' => [...]], ...]
    $e->getRawBody();     // the response body, exactly as it arrived
    $e->getPrevious();    // the underlying Guzzle exception
}
```

`getMessage()` is always something you can display. Not every failure is a JSON:API document — the
gateway answers authentication failures (401, 422) with plain text and returns an empty body for
403 — so `getErrors()` is empty in those cases while `getMessage()` still reads sensibly. Network
failures arrive the same way, with a status code of `0`.

## Helpers

The SDK also includes a `Helpers` class with useful methods. For example, you can use the
`convertAlpha2ToAlpha3` method to convert a country code from ISO 3166-1 alpha-2 to ISO 3166-1
alpha-3.

```php
$alpha3 = Edge\Helpers::convertAlpha2ToAlpha3('US');
```

## Development

The planned resource-oriented API is documented in the
[backend resource contract](docs/resource-contract.md), including supported operations,
field mappings, and known backend discrepancies. Concrete endpoint classes are not implemented yet;
the instance and static clients documented above currently return decoded documents.

The PHP version is pinned in `mise.toml` and managed with [mise](https://mise.jdx.dev):

```bash
mise install
composer install
composer test
```

The suite uses Guzzle's `MockHandler`, so it asserts on the exact requests the SDK builds — URLs,
headers, verbs and bodies — without touching the network.

## Contributing

Contributions are welcome. Please submit a pull request or create an issue if you have any
improvements or find any bugs.
