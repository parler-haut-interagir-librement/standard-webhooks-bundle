# Reference

| Type | Kind | Role |
|---|---|---|
| `PhilStandardWebhooksBundle` | `final class`, `AbstractBundle` | Alias `phil_standard_webhooks`; the configuration and the services |
| `Application\SigningKeys` | `final readonly class` | The configured signing keys: the current one, and the others of a rotation |
| `Application\Library` | `final class` | The library's types the bundle registers, by name |
| `Infrastructure\Http\VerifiedWebhookResolver` | controller argument resolver | A `VerifiedWebhook` argument, verified; `400` or `401`; the receiver built for such an argument only |
| `Infrastructure\Http\WebhookSender` | service | One signed attempt over Symfony's HTTP client, to `https` URLs outside private networks unless allowed |
| `Infrastructure\Http\DeliveryFailed` | exception, a `WebhookException` | A delivery not acknowledged or refused: its id, the status if any, and whether it is `retryable` |
| `Infrastructure\Doctrine\DbalProcessedWebhooks` | `ProcessedWebhookRepository` | The processed ids in their table; `purge()` |
| `Infrastructure\Doctrine\ProcessedWebhookTable` | schema | `phil_standard_webhooks_processed` |
| `Infrastructure\Doctrine\ProcessedWebhookSchemaListener` | Doctrine listener | The table in the ORM's schema |
| `Infrastructure\Console\GenerateKeyCommand` | console command | `phil:standard-webhooks:generate-key` |

The protocol's types — `WebhookReceiver`, `VerifiedWebhook`, the keys, the exceptions — are
`phil/standard-webhooks`'s: see its reference (`doc/reference.md` of `phil/standard-webhooks`).
