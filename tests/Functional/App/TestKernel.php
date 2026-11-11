<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Functional\App;

use Override;
use Phil\StandardWebhooks\Ed25519SecretKey;
use Phil\StandardWebhooks\ProcessedWebhookRepository;
use Phil\StandardWebhooks\VerificationKeyProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * An application as small as the bundle needs: FrameworkBundle, DoctrineBundle on SQLite, the bundle
 * (`config/bundles.php`), and the receiving endpoint `POST /webhooks` that the documentation describes. The bundle
 * is configured for the audience `receiver-1`, a tolerance of five minutes and the sender's keys; the application
 * replaces the configured verification keys with a provider that knows the HMAC secret and finds the Ed25519 key on
 * reload. The HTTP client hands each request to this same kernel, so that what the sender sends, the receiver
 * receives; the clock is fixed at {@see self::NOW}.
 *
 * The `phil_standard_webhooks` configuration is merged over the kernel's own, so that a test can boot the same
 * application on a configuration the bundle refuses; each configuration compiles a container of its own.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string NOW = '@1793448000';

    public const string AUDIENCE = 'receiver-1';

    public const int TOLERANCE = 300;

    /**
     * The secret the sender and the receiver share, for `v1`.
     */
    public const string HMAC_SECRET = 'whsec_a2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';

    /**
     * @param array<string, mixed> $configuration the `phil_standard_webhooks` configuration
     */
    public function __construct(string $environment = 'test', bool $debug = true, private readonly array $configuration = [])
    {
        parent::__construct($environment, $debug);
    }

    /**
     * The sender's Ed25519 key, for `v1a`: a fixed seed, so that the receiver's container can hold its public half.
     */
    public static function senderKey(): Ed25519SecretKey
    {
        return Ed25519SecretKey::fromBytes(str_repeat("\x07", 32));
    }

    #[Override]
    public function getProjectDir(): string
    {
        return __DIR__;
    }

    #[Override]
    public function getCacheDir(): string
    {
        // One container per configuration: it is compiled into it.
        return sys_get_temp_dir().'/phil-standard-webhooks-bundle-test/'.$this->fingerprint().'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/phil-standard-webhooks-bundle-test/'.$this->fingerprint().'/log';
    }

    private function fingerprint(): string
    {
        return hash('xxh128', __DIR__.json_encode($this->configuration, \JSON_THROW_ON_ERROR));
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);
        $container->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);

        $services = $container->services();

        // The refused deliveries are answered without an exception, but the default logger would print any other.
        $services->set('logger', NullLogger::class);
        $services->set('clock', MockClock::class)->args([self::NOW]);

        $services->set(RotatingKeyProvider::class)
            ->args([self::HMAC_SECRET, self::senderKey()->publicKey()->toString()])
            ->public();
        $services->alias(VerificationKeyProvider::class, RotatingKeyProvider::class);
        $services->set(KernelForwarder::class)->args([service('kernel')]);
        $services->set('http_client', MockHttpClient::class)->args([service(KernelForwarder::class)]);
        $services->set(WebhookController::class)
            ->args([service(ProcessedWebhookRepository::class), service('clock')])
            ->tag('controller.service_arguments')
            ->public();

        $container->extension('phil_standard_webhooks', [
            'signing_keys' => [self::senderKey()->toString()],
            'tolerance' => self::TOLERANCE,
            'audience' => self::AUDIENCE,
            // The sender posts to this kernel, at http://localhost.
            'allow_private_networks' => true,
            'allow_plain_http' => true,
            'allow_localhost' => true,
            ...$this->configuration,
        ]);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('webhooks', '/webhooks')->controller(WebhookController::class)->methods(['POST']);
    }
}
