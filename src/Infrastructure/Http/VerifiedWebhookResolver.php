<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Infrastructure\Http;

use Closure;
use InvalidArgumentException;
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
 * body larger than the limit, 1 MiB by default, is a `413`, a malformed delivery a `400`, a refused one a `401` that
 * names its reason in `X-Rejection-Reason`. The controller then claims the id, processes the event, and acknowledges.
 *
 * The body is bounded before it is read whole or hashed: a `Content-Length` above the limit is refused unread, and the
 * body is read up to one byte past the limit, so that a sender, signed or not, cannot make the endpoint hold or hash
 * more.
 *
 * The resolver is asked about every argument of every controller, so it takes the receiver as a closure and builds
 * it only for a `VerifiedWebhook` argument: a verification key that cannot be read fails the webhook endpoint, as
 * a server error, and no other page; and no key is parsed by a request that receives no webhook.
 */
final readonly class VerifiedWebhookResolver implements ValueResolverInterface
{
    public const string REASON_HEADER = 'X-Rejection-Reason';

    /** 1 MiB, the largest body read by default. */
    public const int MAX_BODY_BYTES = 1_048_576;

    /** @var Closure(): WebhookReceiver */
    private Closure $receiver;

    /** @var int<1, max> */
    private int $maxBodyBytes;

    /**
     * @param WebhookReceiver|Closure(): WebhookReceiver $receiver
     * @param int                                        $maxBodyBytes the largest body read, at least 1 byte
     *
     * @throws InvalidArgumentException when the limit is below 1 byte
     */
    public function __construct(
        WebhookReceiver|Closure $receiver,
        private ClockInterface $clock,
        int $maxBodyBytes = self::MAX_BODY_BYTES,
    ) {
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('The largest body of a webhook is at least 1 byte.');
        }

        $this->receiver = $receiver instanceof WebhookReceiver ? static fn (): WebhookReceiver => $receiver : $receiver;
        $this->maxBodyBytes = $maxBodyBytes;
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
        $body = $this->body($request);

        try {
            $headers = WebhookHeaders::fromStrings(
                $request->headers->get(WebhookHeaders::ID),
                $request->headers->get(WebhookHeaders::TIMESTAMP),
                $request->headers->get(WebhookHeaders::SIGNATURE),
            );

            return [$receiver->receive($headers, $body, $this->clock->now())];
        } catch (InvalidWebhookValue $invalidWebhookValue) {
            throw new BadRequestHttpException($invalidWebhookValue->getMessage(), $invalidWebhookValue);
        } catch (WebhookRejected $webhookRejected) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, $webhookRejected->getMessage(), $webhookRejected, [self::REASON_HEADER => $webhookRejected->reason->value]);
        }
    }

    /**
     * The body, at most the limit: a `Content-Length` above it is refused before a byte is read, and a body that turns
     * out longer is refused once the byte past the limit is read.
     *
     * @throws HttpException a `413` when the body is larger than the limit, a `400` when it cannot be read
     */
    private function body(Request $request): string
    {
        $length = $request->headers->get('Content-Length');

        if (null !== $length && 1 === preg_match('/^\d+$/D', $length) && (int) $length > $this->maxBodyBytes) {
            throw $this->tooLarge();
        }

        $body = stream_get_contents($request->getContent(true), $this->maxBodyBytes + 1);

        if (false === $body) {
            throw new BadRequestHttpException('The body of the webhook cannot be read.');
        }

        if (\strlen($body) > $this->maxBodyBytes) {
            throw $this->tooLarge();
        }

        return $body;
    }

    private function tooLarge(): HttpException
    {
        return new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, \sprintf('The body of a webhook is at most %d bytes.', $this->maxBodyBytes));
    }
}
