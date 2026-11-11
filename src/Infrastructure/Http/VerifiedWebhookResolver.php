<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Infrastructure\Http;

use Closure;
use Override;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Exception\WebhookRejected;
use Phil\StandardWebhooks\VerifiedWebhook;
use Phil\StandardWebhooks\WebhookHeaders;
use Phil\StandardWebhooks\WebhookReceiver;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A controller argument typed `VerifiedWebhook` is the request's delivery, verified by the configured receiver: a
 * malformed delivery is a `400`, a refused one a `401` that names its reason in `X-Rejection-Reason`. The
 * controller then claims the id, processes the event, and acknowledges.
 *
 * The resolver is asked about every argument of every controller, so it takes the receiver as a closure and builds
 * it only for a `VerifiedWebhook` argument: a verification key that cannot be read fails the webhook endpoint, as
 * a server error, and no other page; and no key is parsed by a request that receives no webhook.
 */
final readonly class VerifiedWebhookResolver implements ValueResolverInterface
{
    public const string REASON_HEADER = 'X-Rejection-Reason';

    /** @var Closure(): WebhookReceiver */
    private Closure $receiver;

    /**
     * @param WebhookReceiver|Closure(): WebhookReceiver $receiver
     */
    public function __construct(
        WebhookReceiver|Closure $receiver,
        private ClockInterface $clock,
    ) {
        $this->receiver = $receiver instanceof WebhookReceiver ? static fn (): WebhookReceiver => $receiver : $receiver;
    }

    /**
     * @return list<VerifiedWebhook>
     */
    #[Override]
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if (VerifiedWebhook::class !== $argument->getType()) {
            return [];
        }

        // Outside the try: a key the configuration cannot read is the server's fault, not the sender's.
        $receiver = ($this->receiver)();

        try {
            $headers = WebhookHeaders::fromStrings(
                $request->headers->get(WebhookHeaders::ID),
                $request->headers->get(WebhookHeaders::TIMESTAMP),
                $request->headers->get(WebhookHeaders::SIGNATURE),
            );

            return [$receiver->receive($headers, $request->getContent(), $this->clock->now())];
        } catch (InvalidWebhookValue $invalidWebhookValue) {
            throw new BadRequestHttpException($invalidWebhookValue->getMessage(), $invalidWebhookValue);
        } catch (WebhookRejected $webhookRejected) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, $webhookRejected->getMessage(), $webhookRejected, [self::REASON_HEADER => $webhookRejected->reason->value]);
        }
    }
}
