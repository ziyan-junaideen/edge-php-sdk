# Payment methods

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
