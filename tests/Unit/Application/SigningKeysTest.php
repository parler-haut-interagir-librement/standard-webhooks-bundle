<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Unit\Application;

use LogicException;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooksBundle\Application\SigningKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SigningKeys::class)]
final class SigningKeysTest extends TestCase
{
    public function testTheFirstKeyIsTheCurrentOne(): void
    {
        $current = Ed25519SecretKey::generate();
        $old = HmacSecret::generate();
        $older = HmacSecret::generate();
        [$key, $others] = SigningKeys::fromStrings([$current->toString(), $old->toString(), $older->toString()])->split();

        self::assertInstanceOf(Ed25519SecretKey::class, $key);
        self::assertSame($current->publicKey()->toString(), $key->publicKey()->toString());
        self::assertSame([$old->toString(), $older->toString()], array_map(static fn (object $other): string => $other instanceof HmacSecret ? $other->toString() : '', $others));
        self::assertSame([$old, []], new SigningKeys(...['a' => $old])->split());
        self::assertFalse(new SigningKeys($old)->isEmpty());
        self::assertTrue(new SigningKeys()->isEmpty());
        self::assertSame(['keys' => 1], new SigningKeys($old)->__debugInfo());
    }

    public function testTheKeysStayOutOfAStackTrace(): void
    {
        $secret = HmacSecret::generate()->toString();

        try {
            SigningKeys::fromStrings([$secret, 'whpk_abc']);
            self::fail('A public key was read as a signing key.');
        } catch (InvalidWebhookValue $invalidWebhookValue) {
            $trace = $invalidWebhookValue->getTrace();
            $below = \array_slice($trace, 0, array_find_key($trace, static fn (array $frame): bool => self::class === ($frame['class'] ?? null)));

            self::assertNotSame([], $below);
            self::assertStringNotContainsString($secret, print_r(array_column($below, 'args'), true));
        }
    }

    public function testNoKeySignsNothing(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('No signing key is configured: set phil_standard_webhooks.signing_keys.');

        SigningKeys::fromStrings([])->split();
    }
}
