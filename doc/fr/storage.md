<!-- phil-dev source-sha256=34238dacff86c0b07c822abcabbe696b08efecf9d0638ee98101e45fbbdfae33 -->
# Stockage des identifiants traités

Avec DoctrineBundle, `Phil\StandardWebhooks\ProcessedWebhookRepository` est `DbalProcessedWebhooks`, sur la connexion
que nomme la configuration.

## La table

| Colonne | Type |
|---|---|
| `id` | `string(255)`, clé primaire ; sur MySQL et MariaDB, en `ascii` comparé octet par octet (`ascii_bin`) |
| `processed_at` | `datetime_immutable`, en UTC, indexée |

`phil_standard_webhooks_processed` se joint au schéma que l'ORM génère pour la connexion, comme le font les tables de
session, de verrou et de Messenger de Symfony : `doctrine:migrations:diff` écrit la migration,
`doctrine:schema:validate` la contrôle. Deux identifiants qui ne diffèrent que par la casse, `msg_A` et `msg_a`, sont
deux identifiants : les collations par défaut de MySQL et de MariaDB ignorent la casse, la colonne en demande donc une
binaire sur ces bases ; PostgreSQL et SQLite comparent déjà les chaînes exactement.

## La revendication

`claim()` insère l'identifiant s'il n'est pas déjà là, en une seule instruction, pour qu'une seconde tentative ne
fasse pas avorter la transaction de traitement : `ON CONFLICT (id) DO NOTHING` sur PostgreSQL, `INSERT IGNORE` sur
MySQL et MariaDB, `INSERT OR IGNORE` sur SQLite. Sur une autre base de données, une insertion simple dont la violation
d'unicité donne `false`. Appelez-la dans la transaction qui traite l'événement, sur la même connexion.

## La purge

`purge($before)` oublie les identifiants traités avant un moment donné, et retourne leur nombre. Gardez-les plus
longtemps que la tolérance et que tout le calendrier de nouvelles tentatives des expéditeurs : une semaine est
courante.

```php
$processed->purge($clock->now()->modify('-7 days'));   // from a scheduled task
```
