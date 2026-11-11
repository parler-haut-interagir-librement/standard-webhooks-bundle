<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Unit;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Phil\StandardWebhooks\FixedKeys;
use Phil\StandardWebhooks\ProcessedWebhookRepository;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\Tolerance;
use Phil\StandardWebhooks\VerificationKeyProvider;
use Phil\StandardWebhooks\Verifier;
use Phil\StandardWebhooks\WebhookReceiver;
use Phil\StandardWebhooksBundle\Application\SigningKeys;
use Phil\StandardWebhooksBundle\Infrastructure\Console\GenerateKeyCommand;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\DbalProcessedWebhooks;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookSchemaListener;
use Phil\StandardWebhooksBundle\Infrastructure\Http\VerifiedWebhookResolver;
use Phil\StandardWebhooksBundle\Infrastructure\Http\WebhookSender;
use Phil\StandardWebhooksBundle\PhilStandardWebhooksBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(PhilStandardWebhooksBundle::class)]
final class PhilStandardWebhooksBundleTest extends TestCase
{
    public function testTheServicesFollowTheConfiguration(): void
    {
        $container = $this->load([['signing_keys' => ['whsk_a', 'whsec_b'], 'verification_keys' => ['whpk_c'], 'tolerance' => 120, 'audience' => 'receiver-1', 'connection' => 'webhooks', 'allow_private_networks' => true, 'allow_plain_http' => true, 'allow_localhost' => true]]);

        self::assertSame('phil_standard_webhooks', $this->extension()->getAlias());
        self::assertSame(['service_container', Signer::class, Verifier::class, 'phil_standard_webhooks.tolerance', SigningKeys::class, FixedKeys::class, WebhookReceiver::class, VerifiedWebhookResolver::class, WebhookSender::class, GenerateKeyCommand::class, DbalProcessedWebhooks::class, ProcessedWebhookSchemaListener::class], array_keys($container->getDefinitions()));
        self::assertSame([[Tolerance::class, 'ofSeconds'], [120]], [$container->getDefinition('phil_standard_webhooks.tolerance')->getFactory(), $container->getDefinition('phil_standard_webhooks.tolerance')->getArguments()]);
        self::assertSame([[SigningKeys::class, 'fromStrings'], [['whsk_a', 'whsec_b']]], [$container->getDefinition(SigningKeys::class)->getFactory(), $container->getDefinition(SigningKeys::class)->getArguments()]);
        self::assertSame([[FixedKeys::class, 'fromStrings'], [['whpk_c']]], [$container->getDefinition(FixedKeys::class)->getFactory(), $container->getDefinition(FixedKeys::class)->getArguments()]);
        self::assertSame(FixedKeys::class, (string) $container->getAlias(VerificationKeyProvider::class));
        self::assertEquals([new Reference(VerificationKeyProvider::class), new Reference('phil_standard_webhooks.tolerance'), 'receiver-1', new Reference(Verifier::class)], $container->getDefinition(WebhookReceiver::class)->getArguments());
        self::assertTrue($container->getDefinition(WebhookReceiver::class)->isPublic());

        $clock = new Reference('clock');
        self::assertEquals([new ServiceClosureArgument(new Reference(WebhookReceiver::class)), $clock], $container->getDefinition(VerifiedWebhookResolver::class)->getArguments());
        self::assertSame([['name' => 'phil_standard_webhooks', 'before' => ['argument_resolver.request_attribute', 'doctrine.orm.entity_value_resolver']]], $container->getDefinition(VerifiedWebhookResolver::class)->getTag('controller.argument_value_resolver'));
        self::assertEquals([new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE), new Reference(Signer::class), new Reference(SigningKeys::class), $clock, '$allowPrivateNetworks' => true, '$allowPlainHttp' => true, '$allowLocalhost' => true], $container->getDefinition(WebhookSender::class)->getArguments());
        self::assertTrue($container->getDefinition(WebhookSender::class)->isPublic());
        self::assertTrue($container->getDefinition(GenerateKeyCommand::class)->hasTag('console.command'));

        $connection = new Reference('doctrine.dbal.webhooks_connection');
        self::assertEquals([$connection], $container->getDefinition(DbalProcessedWebhooks::class)->getArguments());
        self::assertSame(DbalProcessedWebhooks::class, (string) $container->getAlias(ProcessedWebhookRepository::class));
        self::assertTrue($container->getAlias(ProcessedWebhookRepository::class)->isPublic());
        self::assertEquals([$connection], $container->getDefinition(ProcessedWebhookSchemaListener::class)->getArguments());
        self::assertSame([['event' => 'postGenerateSchema', 'connection' => 'webhooks']], $container->getDefinition(ProcessedWebhookSchemaListener::class)->getTag('doctrine.event_listener'));
    }

    public function testTheDefaults(): void
    {
        $container = $this->load([]);

        self::assertSame([300], $container->getDefinition('phil_standard_webhooks.tolerance')->getArguments());
        self::assertSame([[]], $container->getDefinition(SigningKeys::class)->getArguments());
        self::assertSame([[]], $container->getDefinition(FixedKeys::class)->getArguments());
        self::assertNull($container->getDefinition(WebhookReceiver::class)->getArgument(2));
        self::assertSame([false, false, false], [$container->getDefinition(WebhookSender::class)->getArgument('$allowPrivateNetworks'), $container->getDefinition(WebhookSender::class)->getArgument('$allowPlainHttp'), $container->getDefinition(WebhookSender::class)->getArgument('$allowLocalhost')], 'Deliveries go to https URLs outside private networks and localhost by default.');
        self::assertEquals([new Reference('doctrine.dbal.default_connection')], $container->getDefinition(DbalProcessedWebhooks::class)->getArguments());
        self::assertFalse($this->load([['connection' => null]])->hasDefinition(DbalProcessedWebhooks::class));
        self::assertFalse($this->load([['connection' => null]])->hasAlias(ProcessedWebhookRepository::class));
        self::assertFalse($this->load([['connection' => null]])->hasDefinition(ProcessedWebhookSchemaListener::class));
    }

    public function testWithoutDoctrineTheIdsAreKeptElsewhere(): void
    {
        $extension = $this->extension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);

        $without = new ContainerBuilder();
        $without->registerExtension($extension);

        $extension->prepend($without);
        self::assertSame([['connection' => null]], $without->getExtensionConfig('phil_standard_webhooks'));

        $with = new ContainerBuilder();
        $with->registerExtension(new DoctrineExtension());
        $with->registerExtension($extension);

        $extension->prepend($with);
        self::assertSame([], $with->getExtensionConfig('phil_standard_webhooks'));
    }

    public function testARefusedConfiguration(): void
    {
        foreach ([[['audience' => ''], 'The audience is a name, or null to accept any'], [['tolerance' => 0], 'Should be greater than or equal to 1']] as [$config, $message]) {
            try {
                $this->load([$config]);
                self::fail($message);
            } catch (InvalidConfigurationException $invalidConfigurationException) {
                self::assertStringContainsString($message, $invalidConfigurationException->getMessage());
            }
        }

        self::assertSame(['a'], [] === $this->load([['audience' => 'a']])->getDefinition(WebhookReceiver::class)->getArguments() ? [] : [$this->load([['audience' => 'a']])->getDefinition(WebhookReceiver::class)->getArgument(2)]);
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function load(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $this->extension()->load($configs, $container);

        return $container;
    }

    private function extension(): ExtensionInterface
    {
        $extension = new PhilStandardWebhooksBundle()->getContainerExtension();
        self::assertNotNull($extension);

        return $extension;
    }
}
