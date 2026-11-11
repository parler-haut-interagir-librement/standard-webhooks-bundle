# Installation

| Requirement | Constraint | Why |
|---|---|---|
| PHP | `>=8.5` | The declared minimum |
| `phil/standard-webhooks` | `^0.1@dev` | The protocol: headers, keys, signatures, verification; it requires `ext-sodium` |
| `symfony/dependency-injection`, `symfony/config` | `^8.2` | The bundle class and its configuration |
| `symfony/http-foundation`, `symfony/http-kernel` | `^8.2` | The `VerifiedWebhook` controller argument |
| `psr/clock` | `^1.0` | The time a delivery is checked and signed at; the container's `clock` service, which FrameworkBundle registers once `symfony/clock` is installed |
| `symfony/http-client` | optional | `WebhookSender` |
| `doctrine/dbal`, `doctrine/orm`, DoctrineBundle | optional | The processed ids in a table |
| `symfony/console` | optional | `phil:standard-webhooks:generate-key` |

```bash
composer require phil/standard-webhooks-bundle:^0.1@dev
```

```php
// config/bundles.php
return [
    // ...
    Phil\StandardWebhooksBundle\PhilStandardWebhooksBundle::class => ['all' => true],
];
```

Then generate keys and configure them: [configuration.md](configuration.md).

With DoctrineBundle, the bundle adds its table to the schema of its connection: create it with a migration.

```bash
bin/console doctrine:migrations:diff
```
