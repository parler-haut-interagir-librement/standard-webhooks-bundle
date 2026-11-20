<!-- phil-dev source-sha256=d5f37d03c72765b17f49c79a6622b7adfe8a5c1aa154f8dfa0d43de55ce20818 -->
# Réception

Un argument de contrôleur typé `Phil\StandardWebhooks\VerifiedWebhook` est la livraison de la requête, vérifiée par le
`WebhookReceiver` configuré avant que le contrôleur ne s'exécute : l'horodatage selon la tolérance, une signature sous
les clés — rechargées une fois avant de refuser —, l'audience.

| La livraison | La réponse, avant le contrôleur |
|---|---|
| Un corps plus grand que `max_body_bytes`, 1 Mio par défaut | `413` |
| Mal formée : un en-tête absent ou illisible | `400` |
| Refusée : périmée, signée par aucune clé de confiance, destinée à une autre audience | `401`, avec la raison dans `X-Rejection-Reason` : `timestamp_outside_tolerance`, `no_valid_signature`, `audience_mismatch` |

Le corps est borné avant d'être lu en entier ou d'être haché : un `Content-Length` au-dessus de la limite reçoit `413`
avant qu'un octet soit lu, et un corps qui se révèle plus long reçoit `413` dès que l'octet au-delà de la limite est lu.
Un expéditeur, signé ou non, ne peut pas faire retenir ni hacher au point de terminaison plus que la limite. Ne relevez
`max_body_bytes` que pour un expéditeur dont les événements sont plus gros ; Standard Webhooks conseille de petites
charges utiles qui nomment ce qui a changé.

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
(`doc/receiving.md` de `phil/standard-webhooks`) décrit l'ordre des opérations et chaque refus. Chaque expéditeur revendique ses identifiants sous son propre récepteur,
pour que l'expéditeur qui envoie le premier l'identifiant de l'événement d'un autre ne puisse pas faire ignorer cet
événement :

```php
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\DbalProcessedWebhooks;

// The configured receiver claims under `receiver`; another sender under a name of its own.
$partner = $this->processed->forReceiver('partner');   // $this->processed: DbalProcessedWebhooks

if ($partner->claim($webhook->id, $this->clock->now())) {
    // process the partner's event
}
```

Bornez aussi le corps de ces points de terminaison, avant que le récepteur le hache : lisez-le avec une limite, comme le
fait le résolveur.
