<!-- phil-dev source-sha256=339dfc2c0d82fdf36a2151cb7f4f567d01453114b6abfdbf887d0e73b61e6b73 -->
# Réception

Un argument de contrôleur typé `Phil\StandardWebhooks\VerifiedWebhook` est la livraison de la requête, vérifiée par le
`WebhookReceiver` configuré avant que le contrôleur ne s'exécute : l'horodatage selon la tolérance, une signature sous
les clés — rechargées une fois avant de refuser —, l'audience.

| La livraison | La réponse, avant le contrôleur |
|---|---|
| Mal formée : un en-tête absent ou illisible | `400` |
| Refusée : périmée, signée par aucune clé de confiance, destinée à une autre audience | `401`, avec la raison dans `X-Rejection-Reason` : `timestamp_outside_tolerance`, `no_valid_signature`, `audience_mismatch` |

Le contrôleur revendique ensuite l'identifiant, traite l'événement et répond `2xx`, dans une même transaction : une
nouvelle tentative d'une livraison déjà traitée trouve son identifiant revendiqué, et elle est confirmée sans être
traitée deux fois.

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

Une application qui a plusieurs expéditeurs, chacun avec ses propres clés, construit un `WebhookReceiver` par
expéditeur et l'appelle depuis ses contrôleurs ; la documentation de réception de la bibliothèque
(`doc/receiving.md` de `phil/standard-webhooks`) décrit l'ordre des opérations et chaque refus.
