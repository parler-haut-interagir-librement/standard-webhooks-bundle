<!-- phil-dev source-sha256=213f1da4fac258d2a11d7459c9eaf3a7ec79b594919834bbe0a4ffebd14aea9a -->
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

Un test d'intégration, `MySqlProcessedWebhooksTest`, revendique deux identifiants qui ne diffèrent que par la casse
sur un vrai serveur MySQL, quand `PHIL_TEST_MYSQL_URL` en nomme un ; il est sauté sinon.

Le protocole lui-même est testé dans `phil/standard-webhooks`, contre les vecteurs de la RFC 8032 et de Standard
Webhooks.
