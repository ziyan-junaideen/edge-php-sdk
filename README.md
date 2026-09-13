# Edge PHP SDK

A lightweight PHP client for the [Edge](https://tryedge.io) payment gateway's v2
[JSON:API](https://jsonapi.org/). It supports PHP 7.3 and later.

## Installation

```bash
composer require edge-payment-technologies/edge-php-sdk:^2.0
```

Version 2 targets the Edge v2 API and is not compatible with 1.x.

## API keys

Edge issues two kinds of key:

| Key | Looks like | Where it goes |
| --- | --- | --- |
| Secret | `ept_live_s…` / `ept_sandbox_s…` | This SDK, on your server. Never expose it to a browser. |
| Publishable | `ept_live_b…` / `ept_sandbox_b…` | The browser, where the Edge JS SDK mounts the hosted payment form. |

The key prefix selects live or sandbox, so you switch environments by switching keys. Take a
secret key from the Developers tab of the Edge dashboard.

## Quick start

Create a client with your secret key:

```php
$client = new Edge\ApiClient('ept_sandbox_s...');
```

### Resource request

Resource classes build the request for you and return typed, read-only objects:

```php
$result = Edge\Customer::create($client, [
    'attributes' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
]);

$customer = $result->data;   // Edge\Customer
$customer->id;
$customer->email;            // 'ada@example.com'
$customer->created_at;       // DateTimeImmutable

$page = Edge\Customer::list($client, ['page' => ['size' => 25]]);
foreach ($page->data as $customer) {
    echo $customer->name, PHP_EOL;
}
```

Every resource offers the operations the API supports — `list`, `show`, `create`, `update`
and, for payments, `confirm`. The API has no delete. See the
[resource pages](docs/README.md#resources) for each resource's fields and examples.

### Low-level request

For anything without a resource class, send JSON:API documents directly. Responses come back
as the decoded document (`stdClass`):

```php
$customers = $client->get('customers', [
    'filter' => ['email' => 'ada@example.com'],
    'page' => ['size' => 25],
]);

$first = $customers->data[0];
$first->id;
$first->attributes->name;
```

`create()`, `update()` and `confirm()` work the same way. See the
[low-level client](docs/low-level-client.md) guide.

## Configuration

`ApiClient` takes an optional array of options:

```php
$client = new Edge\ApiClient('ept_sandbox_s...', [
    'base_uri' => 'https://api.tryedge.test:4001', // default: https://api.tryedge.io/v2/
    'verify' => false,                             // TLS; disable only for local self-signed certs
    'user_agent_suffix' => 'ExampleApp/1.0',       // identifies your app in the User-Agent
]);
```

## Error handling

Every failed request throws `Edge\Exception`, including network failures (status `0`):

```php
try {
    Edge\Customer::show($client, 'missing-customer');
} catch (Edge\Exception $e) {
    $e->getMessage();     // Always a displayable message
    $e->getStatusCode();  // e.g. 404
    $e->getErrors();      // JSON:API error objects, empty for plain-text responses
    $e->getRawBody();     // The response body as received
}
```

## Documentation

- [Payment flow](docs/payment-flow.md) — take a card payment with the hosted payment form.
- [Resource API](docs/resource-api.md) — results, relationships, pagination and value types.
- [Low-level client](docs/low-level-client.md) — raw requests, the static `Edge\Client`, idempotency, helpers.
- [All documentation](docs/README.md)

## Development

```bash
mise install      # installs the pinned PHP version
composer install
composer test
```

Tests use Guzzle's `MockHandler` and never call the live API. CI runs PHP 7.3, 8.0, 8.3 and 8.4.

## Contributing

Contributions are welcome. Please open an issue or submit a pull request.
