# Customers

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
