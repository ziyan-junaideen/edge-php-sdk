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

Customer, ConsumerAddress, PaymentMethod, PaymentDemand, PaymentSubscription, and
RefundDemand endpoint classes are available below.
Internally, `ApiClient::requestResponse()` returns
an `Edge\Response` for a single request, retaining status, headers, and the raw body for
resource decoding without storing mutable last-response state on the client.

## Shared resource operations

`Edge\ApiResource` connects the instance client and resource decoder. Endpoint subclasses
opt into `Edge\Operations\ListOperation`, `ShowOperation`, `CreateOperation`,
`UpdateOperation`, and `ConfirmOperation` individually. Unsupported methods are absent.

## Customers

`Edge\Customer` supports `list`, `show`, `create`, and `update`. It has no confirm or
delete operation. Each call returns an `Edge\ResourceResult`.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test'); // Replace with your secret key.

$result = Edge\Customer::create($client, [
    'attributes' => ['name' => 'Ada', 'email' => 'ada@example.com'],
    'relationships' => [],
    'query' => ['fields' => ['customers' => ['name', 'email']]],
]);
$customer = $result->data; // Edge\Customer
$updated = Edge\Customer::update($client, $customer, [
    'attributes' => ['description' => 'Updated description'],
]);
$page = Edge\Customer::list($client, [
    'include' => ['addresses'],
    'sort' => ['-created_at', 'name'],
    'page' => ['size' => 25],
]);
$shown = Edge\Customer::show($client, $customer->id, ['include' => ['addresses']]);
$createdAt = $shown->data->created_at; // DateTimeImmutable when present and valid.
$addresses = $shown->data->getRelated('addresses'); // Resolve included records locally.
$status = $shown->status;
$nextPage = $page->links; // Returned links; fetching another page is explicit.
```

Customer fields are `name`, `phone_number`, `email`, `description`, `created_at`,
`blocked_at`, and `updated_at`. The three timestamps decode to `DateTimeImmutable`;
null stays null and invalid timestamps become `Unavailable::DECODING_FAILURE`.
Attributes are read-only; sparse omissions are `Unavailable::UNFETCHED`, and unknown
fields are preserved. Customer has no money mapping.

Relationships are `merchant`, `addresses`, and `payment_demands`. Included customers
decode as `Edge\Customer` by default, including in other resource results. Related types
without an implemented class remain generic resources; missing included records remain
linkage. The server assigns merchant. Although the backend accepts address linkage on
create/update, its current customer changesets do not persist it; payment-demand relationship
writes are not supported. `blocked_at` is readable but is not writable through these operations.

## Consumer addresses

`Edge\ConsumerAddress` supports `list`, `show`, `create`, and `update`, returning
`Edge\ResourceResult`. It has no confirm or delete operation.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test'); // Replace with your secret key.
$result = Edge\ConsumerAddress::create($client, [
    'attributes' => [
        'line_1' => '123 Main St', 'line_2' => 'Apt. 1',
        'city' => 'Los Angeles', 'state' => 'CA', 'zip' => '90001', 'country' => 'USA',
    ],
    'relationships' => ['customer' => ['type' => 'customers', 'id' => 'example-customer']],
    'query' => ['include' => ['customer']],
]);
$address = $result->data; // Edge\ConsumerAddress
$customer = $address->getRelated('customer'); // Included Customer, or unresolved Linkage.
$updated = Edge\ConsumerAddress::update($client, $address, [
    'attributes' => ['line_2' => 'Apt. 2'], // Send only the fields to change.
]);
$shown = Edge\ConsumerAddress::show($client, $address->id);
$createdAt = $shown->data->created_at; // DateTimeImmutable when present and valid.
$page = Edge\ConsumerAddress::list($client, [
    'include' => ['customer'], 'sort' => ['-created_at'],
    'fields' => ['consumer_addresses' => ['line_1', 'city', 'country', 'customer']],
    'page' => ['size' => 25],
]);
$status = $page->status;
$links = $page->links; // Pagination is explicit.
```

Address fields use the backend names `line_1`, `line_2`, `city`, `state`, `zip`, and
`country`. The timestamps `created_at`, `updated_at`, and `discarded_at` decode to
`DateTimeImmutable`; explicit null stays null and invalid dates become
`Unavailable::DECODING_FAILURE`. Sparse omissions are `Unavailable::UNFETCHED`.
Attributes are read-only, unknown fields are preserved, and there is no money mapping.
Included addresses also decode as `Edge\ConsumerAddress`, including through Customer's
`addresses` relationship. Relationships resolve locally without HTTP requests.

The server assigns `merchant` and supports `customer` linkage on create. Customer
reassignment through update is not established by the current backend: its controller
resolves `customer`, but the update changeset casts `customer_id`. Address validation
and immutability remain server responsibilities; an address associated with a payment
demand cannot be changed. `discarded_at` is readable but is not writable through these operations.

## Payment methods

`Edge\PaymentMethod` supports only `list` and `show`, returning `Edge\ResourceResult`.
Card collection and tokenization belong in the Edge browser integration. This resource
does not expose create, update, confirm, or delete operations.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test'); // Replace with your secret key.
$page = Edge\PaymentMethod::list($client, [
    'include' => ['customer', 'address'], 'sort' => ['-created_at'],
    'fields' => ['payment_methods' => ['nickname', 'last_four', 'kind', 'customer', 'address']],
    'page' => ['size' => 25],
]);
$result = Edge\PaymentMethod::show($client, 'example-payment-method', [
    'include' => ['customer', 'address'],
]);
$method = $result->data; // Edge\PaymentMethod
$lastFour = $method->last_four;
$createdAt = $method->created_at; // DateTimeImmutable when present and valid.
$customer = $method->getRelated('customer'); // Included Customer, or unresolved Linkage.
$address = $method->getRelated('address'); // Included ConsumerAddress, linkage, or null.
$refreshed = Edge\PaymentMethod::show($client, $method); // Also accepts a matching resource.
$status = $result->status;
$links = $page->links; // Fetch another page explicitly.
```

Readable fields are `nickname`, `card_bin`, `card_cvv_token`, `last_four`,
`card_pan_token`, `description`, `external_state`, `kind`, `expiry_month`, and
`expiry_year`, plus `created_at`, `updated_at`, and `discarded_at`. Expiry fields
retain their wire integers; unfamiliar kinds and external states remain strings.
The three timestamps decode to `DateTimeImmutable`, explicit null stays null, and
invalid dates become `Unavailable::DECODING_FAILURE`. Sparse omissions are
`Unavailable::UNFETCHED`. Attributes are read-only, unknown fields are preserved,
and there is no money mapping.

Relationships are `address`, `customer`, `payment_demands`, and `merchant`.
Included payment methods decode as `Edge\PaymentMethod` by default. Related customer
and address records use their named classes; unimplemented types remain generic
resources. Missing included records remain linkage, and relationship access never
performs HTTP requests.

## Payment demands

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
confirmation response is not a guarantee that processing has succeeded.

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
`capture_method`, 3DS, and AVS values. See the [resource contract](docs/resource-contract.md)
for the complete readable field list.

Relationships are `merchant`, `buyer`, `payer`, `receiver`, `payment_subscription`,
`payment_method`, `billing_address`, and `shipping_address`. Included demands decode as
`Edge\PaymentDemand`; customer, payment-method, and address records use their named
classes. Relationship resolution is local. Merchant and subscription are server-managed.
Create accepts customer, payment-method, and address linkage; update supports customer
and address linkage, but the backend update changeset omits payment-method reassignment.
Null linkage is preserved by the encoder, although the backend does not establish
clearing these relationships through update.

## Payment subscriptions

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
are preserved. See the [resource contract](docs/resource-contract.md) for all readable fields.

Relationships are `merchant`, `buyer`, `payer`, `receiver`, `payment_method`,
`billing_address`, and `shipping_address`. Included subscriptions use the named class,
including PaymentDemand's `payment_subscription`. Resolution stays local. Merchant is
server-managed; customer and address linkage are supported on create/update, while
payment-method reassignment and clearing null relationships have the same backend
update limitations as PaymentDemand.

## Refund demands

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
