# Testing

| Area | What is covered |
|---|---|
| `PhilStandardWebhooksBundle` | Every service, argument, alias and tag; the defaults; no Doctrine connection without DoctrineBundle; refused configurations |
| `SigningKeys` | The current key and the others, none configured, no key in a stack trace |
| `VerifiedWebhookResolver` | The verified argument, other arguments left alone, `400` and `401` with the reason |
| `WebhookSender`, `DeliveryFailed` | Headers, signatures by every key, a retry's id, the `2xx` bounds, no answer, no client |
| `DbalProcessedWebhooks`, `ProcessedWebhookTable`, the schema listener | Claims once in UTC, purges, each platform's insert, the table on its own connection, the binary collation on MySQL and MariaDB |
| `GenerateKeyCommand` | Both schemes, another refused |

A functional suite boots a kernel with FrameworkBundle, DoctrineBundle on SQLite and the bundle, an endpoint whose
controller takes a `VerifiedWebhook`, and an HTTP client that hands each request to the same kernel:
`WebhookEndpointTest` sends with `WebhookSender` and receives — both schemes, a key found on reload, a retry
processed once, each refusal —; `ConfigurationTest` boots and refuses. Installed on its own, where FrameworkBundle
is a development requirement, the suite is skipped.

```sh
vendor/bin/phpunit packages/standard-webhooks-bundle/tests
```

An integration test, `MySqlProcessedWebhooksTest`, claims two ids that differ by their case on a real MySQL server,
when `PHIL_TEST_MYSQL_URL` names one; it is skipped otherwise.

The protocol itself is tested in `phil/standard-webhooks`, against the RFC 8032 and Standard Webhooks vectors.
