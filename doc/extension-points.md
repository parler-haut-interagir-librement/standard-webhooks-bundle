# Extension points

What an application implements, replaces, configures or relies on. The ports and their invariants are
`phil/standard-webhooks`'s.

## Ports

Each is an alias that the application points at a service of its own — see
[Configuration](configuration.md#keys-that-change).

| Port | Shipped | What the application's own does |
|---|---|---|
| `Phil\StandardWebhooks\VerificationKeyProvider` | `FixedKeys`, the configured `verification_keys` | Keys a sender publishes and rotates without a deployment: the receiver reloads them once before refusing a delivery; `keys()` never fetches |
| `Phil\StandardWebhooks\ProcessedWebhookRepository` | `DbalProcessedWebhooks`, on `connection` | Keeps the processed ids elsewhere; `claim()` stays atomic within the processing transaction, so a delivery is processed once |

## Configuration

| Option | What it extends |
|---|---|
| `signing_keys` | The keys the application signs with, the first the current one; the others sign beside it during a rotation |
| `verification_keys` | The keys deliveries are verified with |
| `tolerance`, `audience` | How far a timestamp may be from the clock, and the receiver's name |
| `connection` | Where processed ids are kept; `null` for a store of the application's own |
| `allow_private_networks`, `allow_plain_http` | Whether deliveries may go to private addresses and plain HTTP — for receivers the application itself trusts |

## Services

| Service | How an application relies on it |
|---|---|
| A controller argument typed `VerifiedWebhook` | The delivery, verified before the controller runs — see [Receiving](receiving.md) |
| `Phil\StandardWebhooksBundle\Infrastructure\Http\WebhookSender` | Sends a signed delivery over `http_client`; public — see [Sending](sending.md) |
| `Phil\StandardWebhooks\WebhookReceiver` | Verifies a delivery the application reads itself; public |
