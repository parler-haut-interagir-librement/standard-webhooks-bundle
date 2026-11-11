<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle;

use Override;
use Phil\StandardWebhooksBundle\Application\Library;
use Phil\StandardWebhooksBundle\Application\SigningKeys;
use Phil\StandardWebhooksBundle\Infrastructure\Console\GenerateKeyCommand;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\DbalProcessedWebhooks;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookSchemaListener;
use Phil\StandardWebhooksBundle\Infrastructure\Http\VerifiedWebhookResolver;
use Phil\StandardWebhooksBundle\Infrastructure\Http\WebhookSender;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Standard Webhooks in a Symfony application, on `phil/standard-webhooks`: the keys it signs and verifies with, from
 * its configuration; the receiver, and the `VerifiedWebhook` controller argument; a sender over Symfony's HTTP
 * client; the processed ids in a table of a Doctrine connection; and a command that generates keys.
 *
 * Keys are read when first used, not when the container is built, so that they can come from environment variables;
 * the verification keys by the first request with a `VerifiedWebhook` argument only. Deliveries go to `https` URLs
 * outside private networks unless the configuration allows otherwise.
 */
final class PhilStandardWebhooksBundle extends AbstractBundle
{
    #[Override]
    protected string $extensionAlias = 'phil_standard_webhooks';

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('signing_keys')
                    ->info('The keys this application signs with, "whsk_" or "whsec_": the first is the current one, the others sign beside it during a rotation.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('verification_keys')
                    ->info('The keys deliveries are verified with, "whpk_" or "whsec_"; a VerificationKeyProvider service of the application\'s own replaces them.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->integerNode('tolerance')
                    ->info("How many seconds a delivery's timestamp may be from the clock.")
                    ->min(1)
                    ->defaultValue(300)
                ->end()
                ->scalarNode('audience')
                    ->info('This receiver\'s name, which an envelope\'s "audience" must give; null accepts any.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $audience): bool => '' === $audience)
                        ->thenInvalid('The audience is a name, or null to accept any: %s.')
                    ->end()
                ->end()
                ->scalarNode('connection')
                    ->info('The Doctrine connection that keeps the processed ids; null keeps them elsewhere.')
                    ->defaultValue('default')
                ->end()
                ->booleanNode('allow_private_networks')
                    ->info('Whether deliveries may go to a loopback, private, link-local or reserved address; false guards against server-side request forgery.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('allow_plain_http')
                    ->info('Whether deliveries may go to an http URL; false sends to https only.')
                    ->defaultFalse()
                ->end()
            ->end();
    }

    /**
     * Without DoctrineBundle, the processed ids are kept elsewhere: the application's own repository.
     */
    #[Override]
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('phil_standard_webhooks', ['connection' => null]);
        }
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $clock = new Reference('clock');

        $services->set(Library::SIGNER);
        $services->set(Library::VERIFIER);
        $services->set('phil_standard_webhooks.tolerance', Library::TOLERANCE)
            ->factory([Library::TOLERANCE, 'ofSeconds'])
            ->args([$config['tolerance']]);
        $services->set(SigningKeys::class)
            ->factory([SigningKeys::class, 'fromStrings'])
            ->args([$config['signing_keys']]);
        $services->set(Library::FIXED_KEYS)
            ->factory([Library::FIXED_KEYS, 'fromStrings'])
            ->args([$config['verification_keys']]);
        $services->alias(Library::VERIFICATION_KEY_PROVIDER, Library::FIXED_KEYS);
        $services->set(Library::RECEIVER)
            ->args([new Reference(Library::VERIFICATION_KEY_PROVIDER), new Reference('phil_standard_webhooks.tolerance'), $config['audience'], new Reference(Library::VERIFIER)])
            ->public();
        // Before the resolvers that would give a `VerifiedWebhook` argument another value: the request attribute of
        // the same name, the entity Doctrine would look up.
        $services->set(VerifiedWebhookResolver::class)
            ->args([new ServiceClosureArgument(new Reference(Library::RECEIVER)), $clock])
            ->tag('controller.argument_value_resolver', ['name' => 'phil_standard_webhooks', 'before' => ['argument_resolver.request_attribute', 'doctrine.orm.entity_value_resolver']]);
        $services->set(WebhookSender::class)
            ->args([new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE), new Reference(Library::SIGNER), new Reference(SigningKeys::class), $clock])
            ->arg('$allowPrivateNetworks', $config['allow_private_networks'])
            ->arg('$allowPlainHttp', $config['allow_plain_http'])
            ->public();

        if (class_exists(Command::class)) {
            $services->set(GenerateKeyCommand::class)->tag('console.command');
        }

        if (\is_string($config['connection'])) {
            $connection = new Reference(\sprintf('doctrine.dbal.%s_connection', $config['connection']));
            $services->set(DbalProcessedWebhooks::class)->args([$connection]);
            $services->alias(Library::PROCESSED_WEBHOOKS, DbalProcessedWebhooks::class)->public();
            $services->set(ProcessedWebhookSchemaListener::class)
                ->args([$connection])
                ->tag('doctrine.event_listener', ['event' => 'postGenerateSchema', 'connection' => $config['connection']]);
        }
    }
}
