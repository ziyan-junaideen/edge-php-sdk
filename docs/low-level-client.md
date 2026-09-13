# Low-level client

The low-level client sends JSON:API documents you build yourself and returns the decoded response
as plain `stdClass` objects. Use it for endpoints without a resource class, or when you want the
raw document. For typed resources, see the [resource API](resource-api.md).

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

HTTP 4xx/5xx responses throw even when an injected Guzzle client disables `http_errors`
or omits its error middleware. Status, raw body, and JSON:API errors are preserved;
`getPrevious()` is null when the HTTP client returned the response without throwing.

Customer, ConsumerAddress, PaymentMethod, PaymentDemand, PaymentSubscription,
RefundDemand, Merchant, Event, and WebhookSubscription endpoint classes are documented under [resources](README.md#resources).
Internally, `ApiClient::requestResponse()` returns
an `Edge\Response` for a single request, retaining status, headers, and the raw body for
resource decoding without storing mutable last-response state on the client.

## Static client

The static `Edge\Client` predates `ApiClient` and reads a global key, so set it before making
requests:

```php
Edge\Auth::setApiKey('ept_sandbox_s...');
```

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

## Static client configuration

By default the static client talks to production. Override it for local development, either in code or
through the `EDGE_API_BASE_URI` environment variable:

```php
Edge\Client::setBaseUri('https://api.tryedge.test:4001');
Edge\Client::setVerifySsl(false); // only for a local, self-signed certificate
```

You can also identify your application in the `User-Agent`:

```php
Edge\Client::setUserAgentSuffix('WooCommerce/9.1.2');
```

## Errors

Failed requests from either client throw `Edge\Exception`. `getMessage()` is always something you
can display. Not every failure is a JSON:API document — the gateway answers authentication
failures (401, 422) with plain text and returns an empty body for 403 — so `getErrors()` is empty
in those cases while `getMessage()` still reads sensibly. Network failures arrive the same way,
with a status code of `0`. `getPrevious()` returns the underlying Guzzle exception when there is one.

## Helpers

`Edge\Helpers::convertAlpha2ToAlpha3()` converts an ISO 3166-1 alpha-2 country code to alpha-3,
which is handy when building addresses:

```php
$alpha3 = Edge\Helpers::convertAlpha2ToAlpha3('US'); // 'USA'
```
