# Consumer addresses

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
