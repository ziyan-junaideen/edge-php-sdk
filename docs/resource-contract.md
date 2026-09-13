# Resource-oriented API contract

This is the implementation reference for [issue #5](https://github.com/ziyan-junaideen/edge-php-sdk/issues/5), established by [part 1, issue #6](https://github.com/ziyan-junaideen/edge-php-sdk/issues/6). The API described below is planned; this document does not add resource classes or change the existing SDK.

## Provenance and scope

Reviewed on 2026-09-13 against the clean backend checkout at commit `e40c55be18d0f90014c6e556fc3b0688012fa01d`. Backend paths below are relative to the private Edge backend, not files distributed with this package. This document records the public wire contract without reproducing private implementation source.

The source review covered:

- `openapi.json`: all nine member/collection schemas, resource and confirmation paths, query parameters, and `payment_line_item`.
- `priv/openapi/description.md`: authentication, JSON:API, filtering, and first-cycle proration.
- `lib/core_web/router.ex`: operation availability and both confirmation routes.
- `lib/core_http/views/{customers,consumer_addresses,payment_methods,payment_demands,payment_subscriptions,refund_demands,merchants,events,webhook_subscriptions}.ex`: attributes and relationships.
- The matching nine `lib/core_http/controllers/*_controller.ex` files: read/write paths, relationship resolution, and confirmation handling.
- `lib/core/transactions/payment.ex`: composed payment fields and casts; `payment_intent.ex`, `payment_demand.ex`, `payment_subscription.ex`, `refund_demand.ex`, `line_item.ex`, `shipping_detail.ex`, and `tax_detail.ex` in the same directory: effective writes and value types.
- `lib/core/consumer/{customer,address}.ex` and `lib/core/developers/webhook_subscription.ex`: effective writes and server-managed values.

Architectural comparison: Elixir SDK commit `098f67c83f4396536fa14c8e9948583eddb1e72b`, `lib/ept_sdk/resource.ex`, `encoder.ex`, and the nine resource modules. Its field lists and operations are not authoritative. PHP compatibility baseline: `19759b9b428e54e00f194cbc0adacf9cd950908d`.

Use this recorded contract when backend sources are unavailable. When refreshing it, compare actual views, composed helpers, controllers, and changesets with OpenAPI; discrepancies at this snapshot are recorded below. Do not fill gaps from the Elixir SDK or backend database fields that the API does not expose.

## Agreed PHP interface

Keep namespace `Edge`, package `edge-payment-technologies/edge-php-sdk`, and PHP `^7.3 || ^8.0` compatibility. Planned usage:

```php
$client = new Edge\ApiClient('ept_sandbox_s_test', [
    'user_agent_suffix' => 'ExampleApp/1.0',
]);
$result = Edge\PaymentDemand::show($client, $id, ['include' => ['buyer']]);
$demand = $result->data;
$amount = $demand->amount; // Edge\Money
$createdAt = $demand->created_at; // DateTimeImmutable
$confirmed = Edge\PaymentDemand::confirm($client, $demand);
```

Only expose the operations enabled in the matrix below, using these signatures:

```php
list($client, $query = [])
show($client, $idOrResource, $query = [])
create($client, $options = [])
update($client, $idOrResource, $options = [])
confirm($client, $idOrResource, $query = [])
```

Each operation requires an explicit `Edge\ApiClient`; it must not read global authentication. Resource arguments must match the called class. Treat IDs as opaque strings and encode each as one URL path segment. Separate write options into `attributes`, `relationships`, and `query`; build the JSON:API envelope internally. Creation supplies `data.type`; update and confirmation also supply the matching `data.id`.

Every operation returns `Edge\ResourceResult` retaining `data`, `included`, `links`, `meta`, HTTP status, headers, and the raw document. Singleton data is a named resource or null; collection data is an array (including `[]`). Unknown types use a generic resource. Preserve unknown attributes, relationships, enum strings, links, metadata, and other document/resource members.

Resource properties are read-only and use backend snake_case names. Keep original wire attributes alongside converted values. Decode known timestamps to `DateTimeImmutable` and established money pairs to immutable `Edge\Money` with integer cents and currency; no money arithmetic. Invalid dates or incomplete/invalid money pairs yield decoding-failure values, never guessed dates, zero amounts, or default currencies.

Unavailable-value objects distinguish unfetched values (for example, excluded by sparse selection), undefined values (absent without evidence of exclusion), and decoding failures. Explicit JSON null stays null. A partially present money pair is unavailable, not valid money; retain both original fields so null and absence remain distinguishable.

Index included resources by type and ID. Resolve relationships locally, retaining unresolved linkage and relationship links/meta. Preserve to-one null and to-many `[]` separately. Links-only relationships remain unfetched. Handle nested/cyclic graphs without recursive expansion. Property access must never initiate HTTP requests; pagination and additional fetches are explicit.

Keep existing static `Edge\Client` / `Edge\Auth` configuration, decoded-document return values, and `Edge\Exception` behavior intact. In particular, the legacy `Client::confirm` accepts supplied attributes; this work does not remove that compatibility behavior. The new resource confirmation interface accepts query options and always sends empty attributes.

## Transport and operation matrix

Base URI: `https://api.tryedge.io/v2/`. Local development: `https://api.tryedge.test:4001/v2/`. Send `Authorization: Bearer <secret key>`, JSON:API `Accept`, and an SDK-identifying `User-Agent` with optional application suffix. Writes use `Content-Type: application/vnd.api+json`. Preserve same-origin URL checks and TLS verification defaults; local self-signed TLS is an explicit development setting.

For the exact type/path segment `R` below, list is `GET /R`, show is `GET /R/{id}`, create is `POST /R`, update is `PATCH /R/{id}`, and confirm is `PATCH /R/{id}/confirm`, all relative to `/v2`.

| PHP resource | JSON:API type / path `R` | List | Show | Create | Update | Confirm |
| --- | --- | --- | --- | --- | --- | --- |
| `Customer` | `customers` | yes | yes | yes | yes | — |
| `ConsumerAddress` | `consumer_addresses` | yes | yes | yes | yes | — |
| `PaymentMethod` | `payment_methods` | yes | yes | — | — | — |
| `PaymentDemand` | `payment_demands` | yes | yes | yes | yes | yes |
| `PaymentSubscription` | `payment_subscriptions` | yes | yes | yes | yes | yes |
| `RefundDemand` | `refund_demands` | yes | yes | yes | — | — |
| `Merchant` | `merchants` | yes | yes | — | — | — |
| `Event` | `events` | yes | yes | — | — | — |
| `WebhookSubscription` | `webhook_subscriptions` | yes | yes | yes | yes | — |

Payment methods, merchants, and events are read-only. No selected resource exposes delete or PUT. Relationship GET routes exist in OpenAPI but do not add automatic relationship fetching or extra resource methods to this project. Related types outside these nine decode generically.

Controllers render successful creates with 201 (including idempotent create replays), and reads, updates, and confirmations with 200. Preserve actual response status rather than synthesizing it. Errors remain `Edge\Exception`: retain JSON:API errors, status, raw body, and underlying transport exception; handle plain-text and empty errors and network failures (status 0) as the existing SDK does.

## Readable attributes

The lists below are the complete named attributes in the current views, including shared payment helpers. Presence is not guaranteed: sparse selection, nulls, and payment-intent-backed responses must be handled. `id` and `type` are resource members, not attributes. All resources have `created_at`; all except Event also have `updated_at`. Both are timestamps; backend `inserted_at` is exposed as `created_at`.

| Resource | Attributes beyond `created_at` / `updated_at` | Wire types |
| --- | --- | --- |
| Customer | `name`, `phone_number`, `email`, `description`; `blocked_at` | strings; timestamp |
| ConsumerAddress | `line_1`, `line_2`, `city`, `state`, `zip`, `country`; `discarded_at` | strings; timestamp |
| PaymentMethod | `nickname`, `card_bin`, `card_cvv_token`, `last_four`, `card_pan_token`, `description`, `external_state`, `kind`; `expiry_month`, `expiry_year`; `discarded_at` | strings (states/kinds remain strings); integers; timestamp |
| PaymentDemand | all shared payment fields below; `processor_state`, `capture_method`; `succeeded_at`; `email_receipt` | shared types; enum strings; timestamp; boolean |
| PaymentSubscription | all shared payment fields below; subscription fields below; `status`; `email_receipt`, `alert_receipt` | shared types; enum string; booleans |
| RefundDemand | `state`, `reason`, `reason_note`, `amount_currency`, `idempotency_key`; `amount_cents` | strings; integer |
| Merchant | `business_name`, `business_description`, `phone_number`, `business_email`, `prior_bankruptcies`, `category_code`, `business_privacy_policy_url`, `business_support_email`, `business_support_url`, `business_terms_url`, `business_timezone`, `business_website`, `business_zip_code`, `entity_type`; `active_at` | strings (including `prior_bankruptcies`); timestamp |
| Event | `data`; `mode`, `resource_id`, `resource_type`, `slug` | object; strings; no `updated_at` |
| WebhookSubscription | `status`, `mode`, `description`, `secret_key`, `url`; `concurrency_limit`; `events`; `archived_at` | strings; integer; string array; timestamp |

### Shared payment fields

PaymentDemand composes `charge_view_fields`, `threeds_view_fields`, `idempotency_view_fields`, `capture_view_fields`, `avs_view_fields`, and `addendum_view_fields`. PaymentSubscription composes the same helpers **except capture**, then adds `subscription_view_fields`.

| Helper | Fields | Wire types / notes |
| --- | --- | --- |
| charge | `confirmed`; `fee_cents`, `amount_cents`; `amount_currency`, `description` | boolean; integers; strings. `fee_cents` is explicitly read-only. `metadata` is commented out of the view. |
| threeds | `threeds_version`, `threeds_cryptogram`, `threeds_status`, `eci`, `directory_transaction_eid`, `acs_transaction_eid`, `payer_timezone` | strings, all explicitly read-only |
| idempotency | `idempotency_key` | string |
| avs | `cvc2_check`, `address_line1_verification`, `postal_code_verification` | enum strings, all explicitly read-only |
| addendum | `discount_cents`; `purchase_reference`, `purchase_kind`; `shipping_detail`, `tax_detail`; `line_items` | integer; string/enum string; objects; object array |
| capture (demands only) | `capture_method` | enum string (`automatic`, `manual`) |

Subscription-specific fields:

| Fields | Wire type |
| --- | --- |
| `slug`, `subscription_notification_email` | string |
| `billing_scheme`, `proration_behavior`, `billing_period` | enum string |
| `canceled_at_period_end` | boolean |
| `billing_cycle_anchor_at`, `trial_end_at` | timestamp |
| `next_billing_at`, `current_period_start_at`, `current_period_end_at`, `last_processed_at`, `last_active_at`, `last_paused_at` | timestamp, explicitly read-only |
| `canceled_at` | string in view/OpenAPI, explicitly read-only; underlying backend field is a timestamp, so decode it as a date with the same failure/null handling |

Current billing-period codes are `one_day`, `seven_days`, `fourteen_days`, `thirty_days`, `one_month`, `six_months`, `twelve_months`; billing scheme is `per_unit`; proration behaviors are `none`, `create_prorations`. These are observations, not SDK enum allowlists. Retain unknown values. Do not substitute the older weekly/monthly names from view prose.

### Embedded attributes and money

`line_items`, `shipping_detail`, and `tax_detail` are attributes, not JSON:API relationships. Preserve their contents, including unknown keys and any returned embedded IDs.

| Attribute | Established nested fields |
| --- | --- |
| `line_items[]` | strings `name`, `sku`, `unit_of_measure`, `description`, `commodity_code`; integer `quantity`; integer/string pairs `amount_cents` / `amount_currency`, `tax_cents` / `tax_currency`, `discount_cents` / `discount_currency` |
| `shipping_detail` | integer `shipping_cents`, string `shipping_currency` |
| `tax_detail` | integer `tax_cents`, string `tax_currency` |

The line-item schema requires amount cents, currency, and quantity, and describes nonnegative monetary values and quantity at least one. Embedded changesets and server validation own acceptance. Retain nested maps/arrays; this contract does not require additional named embedded value classes.

| Resource / location | Money property | Cents field | Currency field |
| --- | --- | --- | --- |
| PaymentDemand, PaymentSubscription, RefundDemand | `amount` | `amount_cents` | `amount_currency` |
| PaymentDemand, PaymentSubscription | `fee` | `fee_cents` | `amount_currency` |

Fee calculation uses the charge's amount and stores only cents; there is no `fee_currency` wire field. The embedded pairs above are established monetary data, but must not be flattened into resource-level properties. Keep top-level `discount_cents` as an integer: there is no top-level `discount_currency` in the view. Do not invent `total_collected` or an `amount_refunded` money mapping. No other selected resource has an established money pair.

The complete date mapping is `created_at` on all nine, `updated_at` on all except Event, Customer `blocked_at`, ConsumerAddress and PaymentMethod `discarded_at`, PaymentDemand `succeeded_at`, Merchant `active_at`, WebhookSubscription `archived_at`, and every timestamp listed in the subscription table plus `canceled_at`. Do not infer other `*_at` fields from Elixir or recursively parse arbitrary Event `data`.

## Writes: exposed fields versus effective mutation

View exposure or absence of a `readonly` annotation does not establish writability. The following is the intersection of API fields and the controller's invoked changesets. Omitted attributes remain omitted; an SDK encoder must not fill defaults or validate business state. The server owns required fields, supported values, permissions, ownership, amount limits, and state transitions. These observations must not become rigid SDK allowlists that discard unknown options.

| Resource | Effective create attributes | Effective update attributes |
| --- | --- | --- |
| Customer | `name`, `email`, `phone_number`, `description` | same |
| ConsumerAddress | `line_1`, `line_2`, `city`, `state`, `zip`, `country` | same, subject to server address immutability |
| PaymentMethod | none | none |
| PaymentDemand | `confirmed`, `amount_cents`, `amount_currency`, `description`, `idempotency_key`, `capture_method`, `email_receipt`, and all addendum fields | `amount_cents`, `amount_currency`, `description`, `email_receipt`, and all addendum fields; incomplete/ready intents or failed demands only |
| PaymentSubscription | `confirmed`, `amount_cents`, `amount_currency`, `description`, `idempotency_key`, `email_receipt`, `alert_receipt`, all addendum fields, plus the subscription cast fields below | only subscription cast fields below and `email_receipt`, on incomplete/ready intents |
| RefundDemand | `reason`, `reason_note`, `amount_cents`, `idempotency_key`; optional `amount_currency` must match the payment | none |
| Merchant | none | none |
| Event | none | none |
| WebhookSubscription | `mode`, `concurrency_limit`, `description`, `events`, `url` | same, or the special `status: "archived"` operation |

Subscription cast fields are `slug`, `billing_cycle_anchor_at`, `billing_period`, `canceled_at_period_end`, `trial_end_at`, `proration_behavior`, and `subscription_notification_email`. The shared cast does not accept `billing_scheme`; its backend default is `per_unit`. Subscription update does not cast amount, description, addendum fields, or `alert_receipt`. Neither payment update casts `idempotency_key`, `confirmed`, or `capture_method`. Timestamps, statuses, fee, 3DS, and AVS results are server-managed unless explicitly listed as writable above.

Customer `blocked_at` and address `discarded_at` are readable but not cast by these writes. Webhook creation generates `secret_key` and sets active status. Webhook update with `status: "archived"` invokes archival, rather than applying the other supplied attributes; other status values and `archived_at` are dropped from ordinary updates. The list endpoint selects active webhook subscriptions. Do not expose delete or claim pause/reactivate through this endpoint.

### Relationships and cardinality

All targets below are exact JSON:API types. “One” linkage is an object or null; “many” linkage is an array. The encoder accepts resources, explicit `{type, id}` linkage, to-many arrays, and null, wrapping each in `relationships.NAME.data`. Shape support does not guarantee the server permits clearing or mutating that association.

| Resource | One | Many | Effective write behavior |
| --- | --- | --- | --- |
| Customer | `merchant` → `merchants` | `addresses` → `consumer_addresses`; `payment_demands` → `payment_demands` | merchant assigned by server; controller resolves addresses on create/update, but Customer changesets do not persist them; no payment-demand write handler |
| ConsumerAddress | `merchant` → `merchants`; `customer` → `customers` | — | customer supported on create; update resolves customer but passes a record to a changeset casting `customer_id`, so reassignment is not established; merchant assigned by server |
| PaymentMethod | `address` → `consumer_addresses`; `customer` → `customers`; `merchant` → `merchants` | `payment_demands` → `payment_demands` | all read-only through this API |
| PaymentDemand | `merchant` → `merchants`; `buyer`, `payer`, `receiver` → `customers`; `payment_subscription` → `payment_subscriptions`; `payment_method` → `payment_methods`; `billing_address`, `shipping_address` → `consumer_addresses` | — | create accepts buyer/payer/receiver, payment method, billing/shipping address; update casts buyer/payer/receiver and billing/shipping IDs, but not payment method; merchant and payment subscription read-only |
| PaymentSubscription | `merchant` → `merchants`; `buyer`, `payer`, `receiver` → `customers`; `payment_method` → `payment_methods`; `billing_address`, `shipping_address` → `consumer_addresses` | — | same create/update relationship behavior as PaymentDemand, subject to subscription update state restriction |
| RefundDemand | `merchant` → `merchants`; `payment_demand` → `payment_demands` | — | create requires payment demand; merchant derived from it |
| Merchant | — | `customers` → `customers`; `payment_demands` → `payment_demands`; `payment_methods` → `payment_methods`; `beneficial_owners` → `beneficial_owners`; `corporate_officials` → `corporate_officials` | all read-only |
| Event | `merchant` → `merchants` | — | read-only |
| WebhookSubscription | `merchant` → `merchants` | `webhook_deliveries` → `webhook_deliveries` | no relationship writes; merchant assigned by server |

Payment update controllers resolve `payment_method` but the JSON:API changesets omit `payment_method_id`. They also dereference resolved records when constructing IDs; do not promise that null linkage clears them. These are backend limitations, not reasons to collapse null into an empty object or array in the encoder. Unknown related types, such as beneficial owners or webhook deliveries, remain generic resources; they do not expand the operation matrix.

### Confirmation and server business rules

Both controllers route confirmation to processing existing records and use the payload's requested preloads, not submitted attribute mutations. Send matching type/ID and `attributes: {}`. Configure supported billing values in create/update before confirming.

- Demands: creation defaults `confirmed` to false and creates an intent; true creates/enqueues a demand. Update/confirm permit incomplete or ready intents and failed demands for retry. Other states are rejected.
- Subscriptions: creation likewise defaults to an intent. Update is restricted to incomplete/ready intents. Confirmation realizes such an intent or retries an eligible last payment of an active subscription; it is not a general edit or unconditional recurring charge.
- Subscription first-cycle proration: `create_prorations` with a future anchor produces an immediate prorated charge; `none` defers the first full charge to the anchor. The SDK does not calculate the amount or schedule.
- Payment creation looks up `idempotency_key` before creating and can return the existing payment/intent. The key is an attribute, not a header; intent validation requires it. Do not add client-side replay storage.
- Refund creation without amount refunds the remaining balance; pending/processing/succeeded refunds reserve balance, failed refunds release it. Currency is inherited, and an explicitly different currency is rejected. Optional merchant-scoped idempotency keys replay the original refund; changes to the payment, amount, reason, or note with the same key are rejected. The SDK must not calculate a refundable balance from a single response field.

These rules describe the inspected server, not validation to duplicate in PHP. For example, customer email requirements, address country/state validation and immutability, webhook URL/concurrency validation, and payment/refund eligibility remain server responsibilities.

## Query encoding

Accept string or array `include`, `sort`, and each sparse field list. Normalize arrays to comma-separated strings, preserving order, dotted paths, and descending `-` prefixes. Preserve nested filters and page parameters. Includes are relative relationship names such as `buyer` or `buyer.addresses`, not `payment_demands.buyer`.

```php
$query = [
    'include' => ['buyer', 'buyer.addresses'],
    'sort' => ['-created_at', 'description'],
    'fields' => [
        'payment_demands' => ['amount_cents', 'amount_currency', 'created_at', 'description', 'buyer'],
        'customers' => ['name', 'addresses'],
    ],
    'filter' => ['processor_state' => 'pending', 'payer.email' => 'buyer@example.com'],
    'page' => ['number' => 1, 'size' => 25],
];
```

The corresponding query values before URL encoding are:

```text
include=buyer,buyer.addresses
sort=-created_at,description
fields[payment_demands]=amount_cents,amount_currency,created_at,description,buyer
fields[customers]=name,addresses
filter[processor_state]=pending
filter[payer.email]=buyer@example.com
page[number]=1
page[size]=25
```

OpenAPI also advertises `page[limit]` / `page[offset]`; preserve those when supplied. Filtering supports relationship IDs (`filter[payer]`) and dotted relationship attributes; combined filters are AND conditions. Query encoding must preserve nested user structures without inventing additional filter operators. Reads use query directly; writes use `options['query']`. OpenAPI advertises fields/include for writes and singleton reads, and filter/sort/page for collections. The encoder should not silently discard additional query keys.

Do not auto-drain pages. Preserve returned pagination links and metadata exactly, including absent/null links and any link objects. Following an absolute link remains subject to same-origin credential protection. OpenAPI's pagination parameters do not prove every controller applies pagination identically.

## Sanitized examples for later implementation

These are synthetic wire-shape examples, not captured API responses or guarantees that partial records satisfy server business validation. IDs are fake UUIDs; the only credential used in PHP examples is `ept_sandbox_s_test`. Examples intentionally omit many readable fields. HTTP headers/status belong in ResourceResult alongside the raw document.

### Singleton with included relationship

Example 200 response to `GET /v2/payment_demands/00000000-0000-4000-8000-000000000001?include=buyer`:

```json
{
  "data": {
    "type": "payment_demands",
    "id": "00000000-0000-4000-8000-000000000001",
    "attributes": {
      "amount_cents": 2500,
      "amount_currency": "USD",
      "fee_cents": 0,
      "processor_state": "pending",
      "created_at": "2026-09-13T10:00:00.123456Z",
      "succeeded_at": null
    },
    "relationships": {
      "buyer": {"data": {"type": "customers", "id": "00000000-0000-4000-8000-000000000002"}},
      "shipping_address": {"data": null}
    },
    "links": {},
    "meta": {}
  },
  "included": [{
    "type": "customers",
    "id": "00000000-0000-4000-8000-000000000002",
    "attributes": {"name": "Example Buyer"},
    "relationships": {"addresses": {"data": []}},
    "links": {}
  }],
  "links": {},
  "meta": {}
}
```

Decode amount/fee and the timestamp, retain explicit nulls, and resolve buyer without a request. Missing included records must leave the same linkage usable. `{"data": null}` is also a valid decoder singleton shape.

### Collection and explicit pagination

Example 200 document demonstrating a returned page link (its presence is illustrative):

```json
{
  "data": [{
    "type": "customers",
    "id": "00000000-0000-4000-8000-000000000002",
    "attributes": {"name": "Example Buyer", "email": "buyer@example.com"},
    "relationships": {},
    "links": {}
  }],
  "included": [],
  "links": {"next": "https://api.tryedge.io/v2/customers?page%5Bnumber%5D=2"},
  "meta": {}
}
```

An empty collection has `data: []`. Retain the link; do not fetch it automatically.

### Create and update

Planned PHP create call and its JSON body for `POST /v2/payment_demands?include=buyer`:

```php
$result = Edge\PaymentDemand::create($client, [
    'attributes' => [
        'confirmed' => false,
        'amount_cents' => 2500,
        'amount_currency' => 'USD',
        'idempotency_key' => 'example-order-001',
        'description' => 'Example purchase',
    ],
    'relationships' => [
        'buyer' => ['type' => 'customers', 'id' => '00000000-0000-4000-8000-000000000002'],
    ],
    'query' => ['include' => ['buyer']],
]);
```

```json
{
  "data": {
    "type": "payment_demands",
    "attributes": {
      "confirmed": false,
      "amount_cents": 2500,
      "amount_currency": "USD",
      "idempotency_key": "example-order-001",
      "description": "Example purchase"
    },
    "relationships": {
      "buyer": {"data": {"type": "customers", "id": "00000000-0000-4000-8000-000000000002"}}
    }
  }
}
```

`PATCH /v2/customers/00000000-0000-4000-8000-000000000002`:

```json
{
  "data": {
    "type": "customers",
    "id": "00000000-0000-4000-8000-000000000002",
    "attributes": {"name": "Updated Example Buyer"},
    "relationships": {}
  }
}
```

Encode empty attributes/relationships as JSON objects (`{}`), using object-compatible PHP encoding rather than an empty array. An omitted relationship differs from `{"data": null}` and `{"data": []}`; the latter two are encoding examples, not assertions that every endpoint permits clearing relations.

### Confirm both payment resources

`PATCH /v2/payment_demands/00000000-0000-4000-8000-000000000001/confirm`:

```json
{"data":{"type":"payment_demands","id":"00000000-0000-4000-8000-000000000001","attributes":{}}}
```

`PATCH /v2/payment_subscriptions/00000000-0000-4000-8000-000000000003/confirm`:

```json
{"data":{"type":"payment_subscriptions","id":"00000000-0000-4000-8000-000000000003","attributes":{}}}
```

Both return a singleton result. Any include/fields options belong in the query. Do not add `confirmed_at`, billing changes, or other attributes to these bodies.

## Discrepancies and implementation handoff

### Backend/OpenAPI differences

- OpenAPI advertises PaymentDemand `amount_refunded_cents`, absent from the inspected payment view/helpers. Preserve it if returned as an unknown field; do not establish a named money mapping from this stale schema entry.
- RefundDemand `reason_note` exists in the current view and changeset but is missing from the checked-in OpenAPI schema. It is included in this contract.
- OpenAPI member schemas contain empty relationship property maps despite publishing relationship routes. The view relationship definitions establish the targets/cardinalities above.
- Subscription `canceled_at` is declared string in the view, but the backend schema stores a timestamp. Its explicit date conversion is recorded above.
- View narrative mentions unsupported deletes, merchant management, old subscription periods, and simplified event names. The router/controller matrix and structured fields take precedence. Narrative filter examples using `/api` and payment `status` should instead use `/v2` and `processor_state`.
- Customer address writes, address customer reassignment, and payment-method updates have the controller/changeset gaps recorded in the relationship table. Do not silently promise those mutations or implement workarounds.
- `EventsController.index` currently queries customers using customer scoping rather than events, while show uses the event lookup. The event list route is declared, but this is a backend defect to resolve separately; do not route the SDK to customers or fabricate event fields.

### Elixir differences

| Reference module | Difference to avoid copying |
| --- | --- |
| Customer | exposes delete and `verified_payment_methods`, neither in the selected API contract |
| ConsumerAddress | exposes delete and omits readable `discarded_at` |
| PaymentMethod | exposes create/update/delete and bank-account token fields absent from the view; expiry fields are integers, not strings; readable `description` is missing |
| PaymentDemand | includes unsupported `customer_reference`, `metadata`, extra lifecycle dates (`refunded_at`, `disputed_at`, `reversed_at`, `failed_at`), and internal relationships (`processor_detail`, `fee_plan`, `merchant_token`, `merchant_integration`, `refund_demands`); misses shared helper fields such as discount, receipt, 3DS, and AVS results |
| PaymentSubscription | exposes delete, `total_collected`, `metadata`, `discarded_at`, `end_at`, and internal relationships including `payment_demands`; misses current receipt, notification, confirmation, idempotency, discount, fee, 3DS/AVS and lifecycle fields |
| RefundDemand | omits `reason`, `reason_note`, `idempotency_key`; adds unexposed processing/success/failure dates and `processor_detail` / `payment_method` relationships |
| Merchant | exposes update, omits business fields, and lists relationships absent from the view (`consumer_addresses`, `events`, `webhook_subscriptions`, `payment_subscriptions`); misses beneficial owners/corporate officials |
| Event | models `slug` as an empty enum; retain arbitrary strings and opaque `data` |
| WebhookSubscription | exposes delete and `active`; backend exposes lifecycle `status`, `archived_at`, and `webhook_deliveries` |

Elixir's explicit-client calls, resource arguments, and empty-attribute confirmation are useful architectural references. Do not copy its client mutation/result shape, finite known-type dispatch, relationship handling, enum assumptions, or link assignment into the PHP design: PHP requires ResourceResult, unknown-type preservation, distinct null/unavailable values, and local included resolution.

Part 1 changes documentation only. Later parts should use mocked HTTP tests for exact methods, encoded paths, headers, query strings, bodies, response/value decoding, and compatibility; never call the live API. Documentation verification consists of comparing this matrix and field inventory against the sources listed above and running `composer validate --strict`; no artificial runtime tests are needed for this part. Browser SDK work, webhook receivers, money arithmetic, publication, releases, and additional resource families remain outside this project.
