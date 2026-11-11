# phil/standard-webhooks-bundle

**Verified webhook deliveries as controller arguments, and signed webhooks sent from Symfony.**

phil/standard-webhooks-bundle brings [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks) 1.0.0
into a Symfony application. It reads the signing and verification keys from configuration and builds the receiver.
A controller takes the verified delivery as an argument. A malformed delivery gets a `400`, and a refused one a `401`
with its reason. The bundle sends one signed attempt over Symfony's HTTP client, keeps the processed ids in a table
of a Doctrine connection, and ships a command that generates keys.

It is for Symfony applications that receive webhooks from other services or send their own. The format, the
signatures and the verification rules live in `phil/standard-webhooks`, a plain PHP library. Use that library alone
outside Symfony.

The bundle plugs into the HTTP kernel through a controller argument resolver. The HTTP client, Doctrine DBAL and the
Console component are optional, each enabling the sender, the storage or the key command. Processed ids are claimed
without aborting the processing transaction, so de-duplication and the work it guards share one transaction.

## Status

| Part | State |
|---|---|
| Configuration: signing and verification keys, tolerance, audience, connection | Implemented and tested |
| `VerifiedWebhookResolver`: the `VerifiedWebhook` controller argument | Implemented and tested |
| `WebhookSender` and `DeliveryFailed` | Implemented and tested |
| `DbalProcessedWebhooks`, its table and schema listener | Implemented and tested |
| `phil:standard-webhooks:generate-key` | Implemented and tested |

## Documentation

| Document | What it covers |
|---|---|
| [Installation](doc/installation.md) | Requirements, registering, the table. |
| [Configuration](doc/configuration.md) | Every key, generating keys, the services, keys that change. |
| [Receiving](doc/receiving.md) | The controller argument, the answers, a complete endpoint. |
| [Sending](doc/sending.md) | `WebhookSender`, retries, failures, the guard on receiver URLs. |
| [Storage](doc/storage.md) | The table of processed ids, claiming, purging. |
| [Extension points](doc/extension-points.md) | What an application implements, configures, replaces or relies on. |
| [Testing](doc/testing.md) | How the bundle is tested. |
| [Reference](doc/reference.md) | Every type of the bundle. |

The protocol — headers, keys, signatures, the order of verification — is documented with `phil/standard-webhooks`.

## Quick start

```yaml
phil_standard_webhooks:
    signing_keys: ['%env(WEBHOOK_SIGNING_KEY)%']
    verification_keys: ['%env(PARTNER_PUBLIC_KEY)%']
    audience: shop
```

```php
#[Route('/webhooks', methods: ['POST'])]
public function __invoke(VerifiedWebhook $webhook): Response
{
    // claim $webhook->id, process $webhook->envelope(), in one transaction
    return new Response(null, 204);
}

$id = $sender->send('https://partner.example/webhooks', $body);   // a WebhookSender
```

## Requirements

PHP 8.5 or later; `phil/standard-webhooks` 0.1, which requires `ext-sodium`; `psr/clock` 1.0; Symfony 8.2's
dependency injection, config, HTTP foundation and HTTP kernel. Symfony's HTTP client, Doctrine DBAL and ORM with
DoctrineBundle, and Symfony Console are optional.

## Contributing

Contributions are welcome on the PHIL monorepo: read the [contribution guide](CONTRIBUTING.md) and the
[code of conduct](CODE_OF_CONDUCT.md). Report a vulnerability privately, as the [security policy](SECURITY.md)
explains, never in a public issue.

## License

MIT, © Philippe Gamache, doing business as Le Gnome Archiviste; a Parler Haut Interagir Librement project. See [LICENSE](LICENSE).
