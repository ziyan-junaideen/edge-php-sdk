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

Resource classes are planned separately. Internally, `ApiClient::requestResponse()` returns
an `Edge\Response` for a single request, retaining status, headers, and the raw body for
future resource decoding without storing mutable last-response state on the client.

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
field mappings, and known backend discrepancies. Resource classes are not implemented yet;
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
