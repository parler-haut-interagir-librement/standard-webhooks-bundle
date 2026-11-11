<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Unit\Infrastructure;

use Phil\StandardWebhooks\Ed25519PublicKey;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooksBundle\Infrastructure\Console\GenerateKeyCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(GenerateKeyCommand::class)]
final class GenerateKeyCommandTest extends TestCase
{
    public function testAnEd25519PairIsTheDefault(): void
    {
        $tester = new CommandTester(new GenerateKeyCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, preg_match('/^Secret key, for the sender only: (whsk_\S+)\nPublic key, for the receivers: (whpk_\S+)\n$/D', $tester->getDisplay(), $keys));
        self::assertTrue(Ed25519SecretKey::fromString($keys[1])->publicKey()->equals(Ed25519PublicKey::fromString($keys[2])));
        self::assertSame(['phil:standard-webhooks:generate-key', 'Generates a Standard Webhooks key'], [new GenerateKeyCommand()->getName(), new GenerateKeyCommand()->getDescription()]);
    }

    public function testASharedSecretOnRequest(): void
    {
        $tester = new CommandTester(new GenerateKeyCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['scheme' => 'v1']));
        self::assertSame(1, preg_match('/^Shared secret, for the sender and one receiver: (whsec_\S+)\n$/D', $tester->getDisplay(), $secret));
        self::assertNotSame('', HmacSecret::fromString($secret[1])->toString());
    }

    public function testAnotherSchemeIsRefused(): void
    {
        $tester = new CommandTester(new GenerateKeyCommand());

        self::assertSame(Command::INVALID, $tester->execute(['scheme' => 'v2']));
        self::assertSame("The scheme is v1a or v1.\n", $tester->getDisplay());
    }
}
