<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Application;

use Phil\StandardWebhooks\FixedKeys;
use Phil\StandardWebhooks\ProcessedWebhookRepository;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\Tolerance;
use Phil\StandardWebhooks\VerificationKeyProvider;
use Phil\StandardWebhooks\Verifier;
use Phil\StandardWebhooks\WebhookReceiver;

/**
 * The types of `phil/standard-webhooks` the bundle registers as services, named here so that the bundle class wires
 * them through its own application layer.
 */
final class Library
{
    public const string SIGNER = Signer::class;

    public const string VERIFIER = Verifier::class;

    public const string TOLERANCE = Tolerance::class;

    public const string FIXED_KEYS = FixedKeys::class;

    public const string VERIFICATION_KEY_PROVIDER = VerificationKeyProvider::class;

    public const string RECEIVER = WebhookReceiver::class;

    public const string PROCESSED_WEBHOOKS = ProcessedWebhookRepository::class;
}
