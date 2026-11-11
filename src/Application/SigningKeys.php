<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Application;

use LogicException;
use Phil\StandardWebhooks\Keys;
use Phil\StandardWebhooks\SigningKey;
use SensitiveParameter;

/**
 * The keys this application signs with, as configured: the first is the current one; the others, during a
 * rotation, sign beside it so that receivers that know only the old key keep accepting.
 */
final readonly class SigningKeys
{
    /** @var list<SigningKey> */
    private array $keys;

    public function __construct(SigningKey ...$keys)
    {
        $this->keys = array_values($keys);
    }

    /**
     * The keys are read one by one, never handed to another function whole, so that no stack trace shows them.
     *
     * @param list<string> $encoded `whsk_` and `whsec_` keys
     */
    public static function fromStrings(#[SensitiveParameter] array $encoded): self
    {
        $keys = [];

        foreach ($encoded as $key) {
            $keys[] = Keys::signing($key);
        }

        return new self(...$keys);
    }

    /**
     * @return array{SigningKey, list<SigningKey>} the current key, then the others
     */
    public function split(): array
    {
        if ([] === $this->keys) {
            throw new LogicException('No signing key is configured: set phil_standard_webhooks.signing_keys.');
        }

        return [$this->keys[0], \array_slice($this->keys, 1)];
    }

    public function isEmpty(): bool
    {
        return [] === $this->keys;
    }

    /**
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return ['keys' => \count($this->keys)];
    }
}
