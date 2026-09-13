# Edge PHP SDK documentation

Start with the [project README](../README.md) for installation and a quick start.

## Guides

- [Low-level client](low-level-client.md) — `ApiClient` options, the static `Edge\Client`,
  query parameters, idempotency, and environment configuration.
- [Resource API](resource-api.md) — shared operation conventions, pagination, results,
  local relationships, value types, and migrating from static calls.
- [Payment flow](payment-flow.md) — creating a payment demand on the server, verifying the
  card in the browser, and confirming it.

## Resources

| Resource | list | show | create | update | confirm |
| --- | --- | --- | --- | --- | --- |
| [Customer](resources/customers.md) | yes | yes | yes | yes | — |
| [ConsumerAddress](resources/consumer-addresses.md) | yes | yes | yes | yes | — |
| [PaymentMethod](resources/payment-methods.md) | yes | yes | — | — | — |
| [PaymentDemand](resources/payment-demands.md) | yes | yes | yes | yes | yes |
| [PaymentSubscription](resources/payment-subscriptions.md) | yes | yes | yes | yes | yes |
| [RefundDemand](resources/refund-demands.md) | yes | yes | yes | — | — |
| [Merchant](resources/merchants.md) | yes | yes | — | — | — |
| [Event](resources/events.md) | yes | yes | — | — | — |
| [WebhookSubscription](resources/webhook-subscriptions.md) | yes | yes | yes | yes | — |

## Reference

- [Resource contract](resource-contract.md) — the backend wire contract the resource API is
  built against, including known backend discrepancies.
