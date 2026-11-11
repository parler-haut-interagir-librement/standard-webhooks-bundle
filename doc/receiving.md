# Receiving

A controller argument typed `Phil\StandardWebhooks\VerifiedWebhook` is the request's delivery, verified by the
configured `WebhookReceiver` before the controller runs: the timestamp against the tolerance, a signature under the
keys — reloaded once before refusing —, the audience.

| The delivery | The answer, before the controller |
|---|---|
| Malformed: a header missing or unreadable | `400` |
| Refused: stale, signed by no trusted key, for another audience | `401`, with the reason in `X-Rejection-Reason`: `timestamp_outside_tolerance`, `no_valid_signature`, `audience_mismatch` |

The controller then claims the id, processes the event and answers `2xx`, in one transaction: a retry of a
delivery already processed finds its id claimed, and is acknowledged without being processed twice.

```php
use Doctrine\DBAL\Connection;
use Phil\StandardWebhooks\{ProcessedWebhookRepository, VerifiedWebhook};
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class InvoiceWebhookController
{
    public function __construct(
        private ProcessedWebhookRepository $processed,
        private Connection $connection,
        private ClockInterface $clock,
        private Invoices $invoices,
    ) {}

    #[Route('/webhooks', methods: ['POST'])]
    public function __invoke(VerifiedWebhook $webhook): Response
    {
        $envelope = $webhook->envelope();

        if ('invoice.paid' !== $envelope->type) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);  // permanent: never 2xx
        }

        $this->connection->transactional(function () use ($webhook, $envelope): void {
            if ($this->processed->claim($webhook->id, $this->clock->now())) {
                $this->invoices->markPaid($envelope->data['id']);
            }
        });

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
```

An application with several senders, each with its own keys, builds a `WebhookReceiver` per sender and calls it
from its controllers; the library's receiving (`doc/receiving.md` of `phil/standard-webhooks`) describes the order of
operations and every refusal.
