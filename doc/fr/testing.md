<!-- phil-dev source-sha256=a2f54073cdf5d44d7bbb98780d743b86d361e6836daf9026c20dcb257a8bc7a8 -->
# Tests

| Domaine | Ce qui est couvert |
|---|---|
| `PhilStandardWebhooksBundle` | Chaque service, argument, alias et étiquette ; les valeurs par défaut ; aucune connexion Doctrine sans DoctrineBundle ; les configurations refusées |
| `SigningKeys` | La clé courante et les autres, aucune configurée, aucune clé dans une trace de pile |
| `VerifiedWebhookResolver` | L'argument vérifié, les autres arguments laissés tranquilles, `400` et `401` avec la raison |
| `WebhookSender`, `DeliveryFailed` | Les en-têtes, les signatures par chaque clé, l'identifiant d'une nouvelle tentative, les bornes de `2xx`, aucune réponse, aucun client |
| `DbalProcessedWebhooks`, `ProcessedWebhookTable`, l'écouteur de schéma | Une revendication unique en UTC, les purges, l'insertion propre à chaque plateforme, la table sur sa propre connexion, la collation binaire sur MySQL et MariaDB |
| `GenerateKeyCommand` | Les deux schémas, un autre refusé |

Une suite fonctionnelle démarre un noyau avec FrameworkBundle, DoctrineBundle sur SQLite et le bundle, un point de
terminaison dont le contrôleur prend un `VerifiedWebhook`, et un client HTTP qui remet chaque requête au même noyau.
`WebhookEndpointTest` envoie avec `WebhookSender` et reçoit — les deux schémas, une clé trouvée au rechargement, une
nouvelle tentative traitée une fois, chaque refus — ; `ConfigurationTest` démarre et refuse. Quand le paquet est
installé seul, là où FrameworkBundle est un prérequis de développement, la suite est sautée.

```sh
vendor/bin/phpunit packages/standard-webhooks-bundle/tests
```

Deux tests d'intégration, `MySqlProcessedWebhooksTest` et `PostgreSqlProcessedWebhooksTest`, revendiquent deux
identifiants qui ne diffèrent que par la casse sur un vrai serveur, et le premier lit la collation `ascii_bin` de
l'identifiant dans l'`information_schema` de MySQL. Ils s'exécutent quand `PHIL_TEST_MYSQL_URL` et
`PHIL_TEST_POSTGRESQL_URL` nomment les serveurs, comme les fixe `phil-dev services test`, et sont sautés sinon.

Le protocole lui-même est testé dans `phil/standard-webhooks`, contre les vecteurs de la RFC 8032 et de Standard
Webhooks.
