<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Functional;

use Override;
use Phil\StandardWebhooksBundle\PhilStandardWebhooksBundle;
use Phil\StandardWebhooksBundle\Tests\Functional\App\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * A key the bundle does not know, an empty audience or a tolerance under one second stop the kernel rather than
 * being silently ignored.
 */
#[CoversClass(PhilStandardWebhooksBundle::class)]
final class ConfigurationTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FunctionalTestCase::skipWithoutEventDispatcher();
    }

    public function testTheKernelBootsWithTheBundleRegistered(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('PhilStandardWebhooksBundle', $kernel->getBundles());
    }

    public function testARefusedConfigurationStopsTheKernel(): void
    {
        foreach ([
            [['secret' => TestKernel::HMAC_SECRET], 'Unrecognized option "secret" under "phil_standard_webhooks"'],
            [['audience' => ''], 'The audience is a name, or null to accept any'],
            [['tolerance' => 0], 'Should be greater than or equal to 1'],
        ] as [$configuration, $message]) {
            try {
                self::bootKernel(['configuration' => $configuration]);
                self::fail($message);
            } catch (InvalidConfigurationException $invalidConfigurationException) {
                self::assertStringContainsString($message, $invalidConfigurationException->getMessage());
            } finally {
                self::ensureKernelShutdown();
            }
        }
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        $configuration = $options['configuration'] ?? [];
        self::assertIsArray($configuration);

        /** @var array<string, mixed> $configuration */
        return new TestKernel('test', true, $configuration);
    }
}
