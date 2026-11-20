<!-- phil-dev source-sha256=735da0fb83f436186d25280697f4b4e490b6670031e529775d5d88c30b5108c3 -->
# Stockage des identifiants traités

Avec DoctrineBundle, `Phil\StandardWebhooks\ProcessedWebhookRepository` est `DbalProcessedWebhooks`, sur la connexion
que nomme la configuration.

## La table

| Colonne | Type |
|---|---|
| `receiver` | `string(64)`, `'default'` par défaut ; sur MySQL et MariaDB, en `ascii` comparé octet par octet (`ascii_bin`) |
| `id` | `string(255)` ; sur MySQL et MariaDB, en `ascii` comparé octet par octet (`ascii_bin`) |
| `processed_at` | `datetime_immutable`, en UTC, indexée |

`phil_standard_webhooks_processed` se joint au schéma que l'ORM génère pour la connexion, comme le font les tables de
session, de verrou et de Messenger de Symfony : `doctrine:migrations:diff` écrit la migration,
`doctrine:schema:validate` la contrôle. La clé primaire est le récepteur et l'identifiant ensemble : chaque récepteur, un
par expéditeur, revendique ses identifiants à part des autres. Un expéditeur qui devine l'identifiant de l'événement d'un
autre et l'envoie le premier fait revendiquer sa propre livraison, jamais celle de l'autre. Deux identifiants qui ne diffèrent que par la casse, `msg_A` et `msg_a`, sont
deux identifiants : les collations par défaut de MySQL et de MariaDB ignorent la casse, la colonne en demande donc une
binaire sur ces bases ; PostgreSQL et SQLite comparent déjà les chaînes exactement.

## La revendication

`claim()` insère l'identifiant du récepteur s'il n'est pas déjà là, en une seule instruction, pour qu'une seconde tentative ne
fasse pas avorter la transaction de traitement : `ON CONFLICT (receiver, id) DO NOTHING` sur PostgreSQL, `INSERT IGNORE` sur
MySQL et MariaDB, `INSERT OR IGNORE` sur SQLite. Sur une autre base de données, une insertion simple dont la violation
d'unicité donne `false`. Appelez-la dans la transaction qui traite l'événement, sur la même connexion.

`DbalProcessedWebhooks` revendique sous le `receiver` configuré, `default` s'il est omis ; `forReceiver('partner')`
revendique sous un autre, sur la même connexion. Un nom compte de 1 à 64 lettres, chiffres, `_`, `.` ou `-` ; tout autre
est refusé par une `InvalidArgumentException`.

## La purge

`purge($before)` oublie les identifiants traités avant un moment donné, ceux de chaque récepteur, et retourne leur nombre. Gardez-les plus
longtemps que la tolérance et que tout le calendrier de nouvelles tentatives des expéditeurs : une semaine est
courante.

```php
$processed->purge($clock->now()->modify('-7 days'));   // from a scheduled task
```

## Mise à niveau depuis la 0.1

La table gagne la colonne `receiver`, et sa clé primaire devient `(receiver, id)`. `doctrine:migrations:diff` écrit la
migration à partir de l'écouteur de schéma ; relisez-la, ou écrivez-la à la main :

```sql
-- PostgreSQL
ALTER TABLE phil_standard_webhooks_processed ADD receiver VARCHAR(64) DEFAULT 'default' NOT NULL;
ALTER TABLE phil_standard_webhooks_processed DROP CONSTRAINT phil_standard_webhooks_processed_pkey;
ALTER TABLE phil_standard_webhooks_processed ADD PRIMARY KEY (receiver, id);

-- MySQL and MariaDB
ALTER TABLE phil_standard_webhooks_processed
    ADD receiver VARCHAR(64) CHARACTER SET ascii DEFAULT 'default' NOT NULL COLLATE `ascii_bin` FIRST,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (receiver, id);
```

Sur PostgreSQL, la clé primaire peut porter un autre nom : `\d phil_standard_webhooks_processed` le montre. SQLite ne peut
pas changer une clé primaire sur place : la migration qu'écrit `doctrine:migrations:diff` copie la table dans une
nouvelle.

Les lignes déjà là prennent le récepteur `default`. Une application qui configure un autre `receiver` les y déplace, dans
la même migration, pour qu'une nouvelle tentative d'une livraison traitée avant la mise à niveau soit encore confirmée
sans être traitée de nouveau :

```sql
UPDATE phil_standard_webhooks_processed SET receiver = 'shop' WHERE receiver = 'default';
```
