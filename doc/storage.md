# Storage of the processed ids

With DoctrineBundle, `Phil\StandardWebhooks\ProcessedWebhookRepository` is `DbalProcessedWebhooks`, on the
connection the configuration names.

## The table

| Column | Type |
|---|---|
| `id` | `string(255)`, primary key; on MySQL and MariaDB, `ascii` compared by its bytes (`ascii_bin`) |
| `processed_at` | `datetime_immutable`, in UTC, indexed |

`phil_standard_webhooks_processed` joins the schema the ORM generates for the connection, as Symfony's own session,
lock and Messenger tables do: `doctrine:migrations:diff` writes the migration, `doctrine:schema:validate` keeps it.
Two ids that differ by their case, `msg_A` and `msg_a`, are two ids: the default collations of MySQL and MariaDB
ignore the case, so the column asks for a binary one there; PostgreSQL and SQLite compare strings exactly already.

## Claiming

`claim()` inserts the id unless it is there, in one statement, so that a second attempt does not abort the
processing transaction: `ON CONFLICT (id) DO NOTHING` on PostgreSQL, `INSERT IGNORE` on MySQL and MariaDB,
`INSERT OR IGNORE` on SQLite. On another database, a plain insert whose unique violation is a `false`. Call it in
the transaction that processes the event, on the same connection.

## Purging

`purge($before)` forgets the ids processed before a moment, and returns how many. Keep them longer than the
tolerance and the senders' whole retry schedule: a week is common.

```php
$processed->purge($clock->now()->modify('-7 days'));   // from a scheduled task
```
