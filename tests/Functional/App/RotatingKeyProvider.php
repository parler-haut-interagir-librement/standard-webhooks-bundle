<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Functional\App;

use Phil\StandardWebhooks\Ed25519PublicKey;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooks\VerificationKeyProvider;
use SensitiveParameter;

/**
 * A sender in the middle of a rotation: the cached keys hold only its HMAC secret, and its key document, read
 * on reload, also publishes its Ed25519 key. The reloads are counted.
 */
final class RotatingKeyProvider implements VerificationKeyProvider
{
    public int $refreshes = 0;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $hmacSecret,
        private readonly string $publicKey,
    ) {
    }

    public function keys(): array
    {
        return [HmacSecret::fromString($this->hmacSecret)];
    }

    public function refresh(): array
    {
        ++$this->refreshes;

        return [HmacSecret::fromString($this->hmacSecret), Ed25519PublicKey::fromString($this->publicKey)];
    }
}
