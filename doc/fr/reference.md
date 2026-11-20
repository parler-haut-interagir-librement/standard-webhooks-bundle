<!-- phil-dev source-sha256=5c15001c4ae1707d613fe1a7e0a3584b8fe1db0f8d235335d504d1e957e98bfd -->
# Référence

| Type | Genre | Rôle |
|---|---|---|
| `PhilStandardWebhooksBundle` | `final class`, `AbstractBundle` | Alias `phil_standard_webhooks` ; la configuration et les services |
| `Application\SigningKeys` | `final readonly class` | Les clés de signature configurées : la courante, et les autres d'une rotation |
| `Application\Library` | `final class` | Les types de la bibliothèque qu'enregistre le bundle, par nom |
| `Infrastructure\Http\VerifiedWebhookResolver` | résolveur d'arguments de contrôleur | Un argument `VerifiedWebhook`, vérifié ; `413`, `400` ou `401` ; le corps borné avant d'être lu en entier ; le récepteur construit seulement pour un tel argument |
| `Infrastructure\Http\WebhookSender` | service | Une tentative signée par le client HTTP de Symfony, vers des URL `https` hors des réseaux privés, sauf permission |
| `Infrastructure\Http\DeliveryFailed` | exception, une `WebhookException` | Une livraison non confirmée ou refusée : son identifiant, l'état s'il y en a un, et si elle est `retryable` |
| `Infrastructure\Doctrine\DbalProcessedWebhooks` | `ProcessedWebhookRepository` | Les identifiants traités d'un récepteur dans leur table ; `forReceiver()`, `purge()` |
| `Infrastructure\Doctrine\ProcessedWebhookTable` | schéma | `phil_standard_webhooks_processed`, à clé le récepteur et l'identifiant |
| `Infrastructure\Doctrine\ProcessedWebhookSchemaListener` | écouteur Doctrine | La table dans le schéma de l'ORM |
| `Infrastructure\Console\GenerateKeyCommand` | commande console | `phil:standard-webhooks:generate-key` |

Les types du protocole — `WebhookReceiver`, `VerifiedWebhook`, les clés, les exceptions — sont ceux de
`phil/standard-webhooks` : voyez sa référence (`doc/reference.md` de `phil/standard-webhooks`).
