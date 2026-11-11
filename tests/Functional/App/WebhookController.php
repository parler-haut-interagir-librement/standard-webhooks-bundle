<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Functional\App;

use Phil\StandardWebhooks\ProcessedWebhookRepository;
use Phil\StandardWebhooks\VerifiedWebhook;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The endpoint of the documentation: the bundle verifies the delivery before the controller runs — `400` when it
 * is malformed, `401` when it is refused —; a type this receiver does not process is `422`, and a delivery is
 * acknowledged with `204` once its id is claimed — or already was.
 */
final readonly class WebhookController
{
    public const string SUPPORTED_TYPE = 'invoice.paid';

    public function __construct(
        private ProcessedWebhookRepository $processed,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(VerifiedWebhook $webhook): Response
    {
        if (self::SUPPORTED_TYPE !== $webhook->envelope()->type) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->processed->claim($webhook->id, $this->clock->now());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
