<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Functional;

use DateTimeImmutable;
use Override;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\SigningKey;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooks\WebhookTimestamp;
use Phil\StandardWebhooksBundle\Tests\Functional\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The bundle in a kernel of its own (`App\TestKernel`), and deliveries signed by the bundle's own `Signer`, as a
 * sender would sign them, then posted to the application's endpoint.
 */
abstract class FunctionalTestCase extends KernelTestCase
{
    #[Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    #[Override]
    protected function setUp(): void
    {
        self::skipWithoutEventDispatcher();
        self::bootKernel();
    }

    /**
     * Symfony's `ServicesBundle` wires the event dispatcher only when `ContainerBuilder::willBeAvailable()` says
     * so, and it does not when `symfony/event-dispatcher` is installed for development only while
     * `symfony/dependency-injection` is not — the bundle installed on its own, with FrameworkBundle in its
     * `require-dev`. No kernel boots there, so the suite runs from an application that requires an HTTP kernel,
     * such as a monorepo root.
     */
    public static function skipWithoutEventDispatcher(): void
    {
        if (!ContainerBuilder::willBeAvailable('symfony/event-dispatcher', EventDispatcher::class, ['symfony/dependency-injection'])) {
            self::markTestSkipped('The event dispatcher is installed for development only: no kernel can boot.');
        }
    }

    /**
     * A service of the test kernel's container.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $id, string $class): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * The headers a sender puts on a delivery of `$body`, signed at `$signedAt` (the kernel's clock by default).
     *
     * @return array<string, string>
     */
    protected function sign(string $body, SigningKey $key, string $id = 'msg_1', ?DateTimeImmutable $signedAt = null): array
    {
        $timestamp = WebhookTimestamp::fromDateTime($signedAt ?? new DateTimeImmutable(TestKernel::NOW));

        return new Signer()->sign(WebhookId::fromString($id), $timestamp, $body, $key)->toArray();
    }

    /**
     * @param array<string, string> $headers
     */
    protected function deliver(array $headers, string $body): Response
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        $request = Request::create('/webhooks', 'POST', content: $body);
        $request->headers->add($headers);

        return $kernel->handle($request);
    }
}
