<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Infrastructure\Http;

use Phil\StandardWebhooks\Exception\WebhookException;
use Phil\StandardWebhooks\WebhookId;
use RuntimeException;
use Throwable;

/**
 * A delivery the receiver did not acknowledge: an answer outside `2xx`, none, or a URL the sender refused to send to.
 * `retryable` says whether retrying it later, with the same id, can succeed: after a `5xx` or no answer, not after a
 * `4xx` or a refused URL.
 *
 * The message is for the sender's operators: it names the status, or the transport error, which a URL given by a
 * third party must not get back — relayed whole, it would tell that party what answers inside the network.
 */
final class DeliveryFailed extends RuntimeException implements WebhookException
{
    private function __construct(
        public readonly WebhookId $id,
        public readonly ?int $status,
        public readonly bool $retryable,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function answered(WebhookId $id, int $status): self
    {
        return new self($id, $status, $status >= 500, \sprintf('The webhook %s was answered %d.', $id->value, $status));
    }

    public static function unanswered(WebhookId $id, Throwable $previous): self
    {
        return new self($id, null, true, \sprintf('The webhook %s was not answered: %s', $id->value, $previous->getMessage()), $previous);
    }

    public static function refused(WebhookId $id, string $reason): self
    {
        return new self($id, null, false, \sprintf('The webhook %s was not sent: %s.', $id->value, $reason));
    }
}
