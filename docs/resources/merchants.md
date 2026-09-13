# Merchants

`Edge\Merchant` supports only `list` and `show`, returning `Edge\ResourceResult`.

```php
$client = new Edge\ApiClient('ept_sandbox_s_test');
$page = Edge\Merchant::list($client, [
    'sort' => ['business_name'],
    'fields' => ['merchants' => ['business_name', 'entity_type', 'active_at']],
]);
$merchants = $page->data; // Array of Edge\Merchant objects.

$result = Edge\Merchant::show($client, 'example-merchant', ['include' => ['customers']]);
$merchant = $result->data;
$name = $merchant->business_name;
$activeAt = $merchant->active_at; // DateTimeImmutable, null, or Edge\Unavailable.
$customers = $merchant->getRelated('customers'); // Resolved locally when included.
$refreshed = Edge\Merchant::show($client, $merchant); // Also accepts a matching resource.
```

Readable attributes are `business_name`, `business_description`, `phone_number`,
`business_email`, `prior_bankruptcies`, `category_code`, `business_privacy_policy_url`,
`business_support_email`, `business_support_url`, `business_terms_url`, `business_timezone`,
`business_website`, `business_zip_code`, `entity_type`, `active_at`, `created_at`, and
`updated_at`. The three timestamps decode to `DateTimeImmutable`; `prior_bankruptcies`
remains a wire string. Merchant has no money mappings. Null stays null, invalid dates
become `Unavailable::DECODING_FAILURE`, and sparse omissions use `Unavailable::UNFETCHED`.
Attributes are read-only; unknown fields and enum values are preserved.

The exposed collection relationships are `customers`, `payment_demands`, `payment_methods`,
`beneficial_owners`, and `corporate_officials`. Included merchants decode as `Edge\Merchant`
throughout resource results. Included beneficial owners and corporate officials use generic
`Edge\Resource` objects; missing included records remain linkage. Relationship access never
fetches data. No create, update, delete, or confirm operations are exposed.
