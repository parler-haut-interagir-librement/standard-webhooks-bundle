# Storage of the processed ids

With DoctrineBundle, `Phil\StandardWebhooks\ProcessedWebhookRepository` is `DbalProcessedWebhooks`, on the
connection the configuration names.

## The table

| Column | Type |
|---|---|
| `receiver` | `string(64)`, `'default'` by default; on MySQL and MariaDB, `ascii` compared by its bytes (`ascii_bin`) |
| `id` | `string(255)`; on MySQL and MariaDB, `ascii` compared by its bytes (`ascii_bin`) |
| `processed_at` | `datetime_immutable`, in UTC, indexed |

`phil_standard_webhooks_processed` joins the schema the ORM generates for the connection, as Symfony's own session,
lock and Messenger tables do: `doctrine:migrations:diff` writes the migration, `doctrine:schema:validate` keeps it.
The primary key is the receiver and the id together: each receiver, one per sender, claims its ids apart from the
others. A sender that guesses the id of another sender's event, and sends it first, has its own delivery claimed, never
the other's. Two ids that differ by their case, `msg_A` and `msg_a`, are two ids: the default collations of MySQL and MariaDB
ignore the case, so the column asks for a binary one there; PostgreSQL and SQLite compare strings exactly already.

## Claiming

`claim()` inserts the receiver's id unless it is there, in one statement, so that a second attempt does not abort the
processing transaction: `ON CONFLICT (receiver, id) DO NOTHING` on PostgreSQL, `INSERT IGNORE` on MySQL and MariaDB,
`INSERT OR IGNORE` on SQLite. On another database, a plain insert whose unique violation is a `false`. Call it in
the transaction that processes the event, on the same connection.

`DbalProcessedWebhooks` claims under the configured `receiver`, `default` when left out; `forReceiver('partner')`
claims under another, on the same connection. A name holds 1 to 64 letters, digits, `_`, `.` or `-`; any other is
refused with an `InvalidArgumentException`.

## Purging

`purge($before)` forgets the ids processed before a moment, those of every receiver, and returns how many. Keep them longer than the
tolerance and the senders' whole retry schedule: a week is common.

```php
$processed->purge($clock->now()->modify('-7 days'));   // from a scheduled task
```

## Upgrading from 0.1

The table gains the column `receiver`, and its primary key becomes `(receiver, id)`. `doctrine:migrations:diff` writes
the migration from the schema listener; review it, or write it by hand:

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

On PostgreSQL, the primary key may carry another name: `\d phil_standard_webhooks_processed` shows it. SQLite cannot
change a primary key in place: the migration that `doctrine:migrations:diff` writes copies the table into a new one.

The rows already there take the receiver `default`. An application that configures another `receiver` moves them to
it, in the same migration, so that a retry of a delivery processed before the upgrade is still acknowledged without
being processed again:

```sql
UPDATE phil_standard_webhooks_processed SET receiver = 'shop' WHERE receiver = 'default';
```
