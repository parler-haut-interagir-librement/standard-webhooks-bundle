# Configuration

```yaml
# config/packages/phil_standard_webhooks.yaml
phil_standard_webhooks:
    # The keys this application signs with: whsk_ (v1a) or whsec_ (v1). The first is the current one; during a
    # rotation, the others sign beside it, so that receivers that know only the old key keep accepting.
    signing_keys: ['%env(WEBHOOK_SIGNING_KEY)%']
    # The keys deliveries are verified with: whpk_ (v1a) or whsec_ (v1).
    verification_keys: ['%env(PARTNER_PUBLIC_KEY)%']
    # How many seconds a delivery's timestamp may be from the clock, either way. 300 when left out.
    tolerance: 300
    # This receiver's name: an envelope must give it as its "audience". null, the default, accepts any.
    audience: shop
    # The Doctrine connection that keeps the processed ids; null keeps them elsewhere. "default" when left out,
    # null without DoctrineBundle.
    connection: default
    # Whether deliveries may go to a loopback, private, link-local or reserved address. false when left out.
    allow_private_networks: false
    # Whether deliveries may go to an http URL rather than https only. false when left out.
    allow_plain_http: false
```

Every key is optional: an application that only receives configures no signing key, one that only sends no
verification key. **Keys are read when first used**, not when the container is built, so that they can come from
environment variables; a key that cannot be read fails the first delivery that needs it. The verification keys are
read by the first request whose controller takes a `VerifiedWebhook` argument, and by no other: an unreadable one
answers that webhook endpoint with a server error, never another page of the application.

`allow_private_networks` and `allow_plain_http` guard the sender against server-side request forgery
([sending.md](sending.md#receiver-urls-given-by-others)). Turn them on only for receivers the application itself
trusts, such as a test kernel at `http://localhost`.

| Refused at build time | Message |
|---|---|
| A key the bundle does not know | `Unrecognized option "…" under "phil_standard_webhooks"` |
| `audience: ''` | `The audience is a name, or null to accept any` |
| `tolerance: 0` | `Should be greater than or equal to 1` |

## Generating keys

```bash
bin/console phil:standard-webhooks:generate-key        # v1a: whsk_… for the sender, whpk_… for the receivers
bin/console phil:standard-webhooks:generate-key v1     # v1: one whsec_… shared by the sender and one receiver
```

The command prints the keys once and keeps nothing: put the secret in the application's secret store.

## The services

| Service | What it is |
|---|---|
| `Phil\StandardWebhooks\WebhookReceiver` | Public; over `VerificationKeyProvider`, the tolerance and the audience |
| `Phil\StandardWebhooks\VerificationKeyProvider` | An alias of `Phil\StandardWebhooks\FixedKeys`, the configured keys |
| `Phil\StandardWebhooks\Signer`, `Phil\StandardWebhooks\Verifier` | The library's own |
| `Phil\StandardWebhooksBundle\Application\SigningKeys` | The configured signing keys |
| `Phil\StandardWebhooksBundle\Infrastructure\Http\WebhookSender` | Public; over the `http_client` service |
| `Phil\StandardWebhooks\ProcessedWebhookRepository` | Public; an alias of `DbalProcessedWebhooks`, with a connection |

## Keys that change

Configured keys are fixed. For keys published by a sender and fetched — a key document, a rotation without a
deployment —, write a `VerificationKeyProvider` and alias it: the receiver then uses it, and reloads it once before
refusing a delivery. The library's extension points (`doc/extension-points.md` of `phil/standard-webhooks`) show one.

```yaml
# config/services.yaml
services:
    App\Webhook\PublishedKeyProvider: ~
    Phil\StandardWebhooks\VerificationKeyProvider: '@App\Webhook\PublishedKeyProvider'
```

The same holds for `Phil\StandardWebhooks\ProcessedWebhookRepository`, to keep the processed ids elsewhere.
