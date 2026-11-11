<!-- phil-dev source-sha256=8471630197ec1d5c4127c51c4d2101b7be1a44d6c4e5d39e91c09386740d6543 -->
# Installation

| Prérequis | Contrainte | Pourquoi |
|---|---|---|
| PHP | `>=8.5` | Le minimum déclaré |
| `phil/standard-webhooks` | `^0.1@dev` | Le protocole : en-têtes, clés, signatures, vérification ; il requiert `ext-sodium` |
| `symfony/dependency-injection`, `symfony/config` | `^8.2` | La classe du bundle et sa configuration |
| `symfony/http-foundation`, `symfony/http-kernel` | `^8.2` | L'argument de contrôleur `VerifiedWebhook` |
| `psr/clock` | `^1.0` | L'heure à laquelle une livraison est vérifiée et signée ; le service `clock` du conteneur, que FrameworkBundle enregistre dès que `symfony/clock` est installé |
| `symfony/http-client` | facultatif | `WebhookSender` |
| `doctrine/dbal`, `doctrine/orm`, DoctrineBundle | facultatif | Les identifiants traités dans une table |
| `symfony/console` | facultatif | `phil:standard-webhooks:generate-key` |

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

Générez ensuite des clés et configurez-les : [configuration.md](configuration.md).

Avec DoctrineBundle, le bundle ajoute sa table au schéma de sa connexion : créez-la avec une migration.

```bash
bin/console doctrine:migrations:diff
```
