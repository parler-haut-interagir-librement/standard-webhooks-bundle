<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Unit\Infrastructure;

use ArrayObject;
use DateTimeImmutable;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Exception\WebhookRejected;
use Phil\StandardWebhooks\FixedKeys;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\Tolerance;
use Phil\StandardWebhooks\VerifiedWebhook;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooks\WebhookReceiver;
use Phil\StandardWebhooks\WebhookTimestamp;
use Phil\StandardWebhooksBundle\Infrastructure\Http\VerifiedWebhookResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[CoversClass(VerifiedWebhookResolver::class)]
final class VerifiedWebhookResolverTest extends TestCase
{
    private const string BODY = '{"type":"invoice.paid","timestamp":"2026-10-31T12:00:00Z","data":{}}';

    public function testAVerifiedWebhookArgumentIsTheRequestsDelivery(): void
    {
        $secret = HmacSecret::generate();
        $resolver = $this->resolver($secret, new DateTimeImmutable('2026-11-03 12:00'));
        $request = $this->request($secret, new DateTimeImmutable('2026-11-03 12:04'));

        $resolved = $resolver->resolve($request, $this->argument(VerifiedWebhook::class));
        self::assertCount(1, $resolved);
        self::assertSame(['msg_1', self::BODY], [$resolved[0]->id->value, $resolved[0]->rawBody]);
        self::assertSame([], $resolver->resolve($request, $this->argument('string')));
        self::assertSame([], $resolver->resolve($request, $this->argument(null)));
        self::assertSame('X-Rejection-Reason', VerifiedWebhookResolver::REASON_HEADER);
    }

    public function testTheReceiverIsBuiltForAVerifiedWebhookArgumentOnly(): void
    {
        $built = new ArrayObject();
        $resolver = new VerifiedWebhookResolver(static function () use ($built): WebhookReceiver {
            $built->append('built');

            return new WebhookReceiver(FixedKeys::fromStrings(['whsec_not base64!']), Tolerance::ofSeconds(60));
        }, new NativeClock());
        $request = Request::create('/page');

        self::assertSame([], $resolver->resolve($request, $this->argument('string')));
        self::assertSame([], $resolver->resolve($request, $this->argument(null)));
        self::assertCount(0, $built, 'A controller without a VerifiedWebhook argument never parses a key.');

        try {
            $resolver->resolve($request, $this->argument(VerifiedWebhook::class));
            self::fail('An unreadable key verified a delivery.');
        } catch (BadRequestHttpException) {
            self::fail("The configuration's fault was answered as the sender's 400.");
        } catch (InvalidWebhookValue $invalidWebhookValue) {
            self::assertStringStartsWith('A webhook secret', $invalidWebhookValue->getMessage(), 'Left to the kernel: a server error.');
        }

        self::assertCount(1, $built);
    }

    public function testWithoutAClockItIsNow(): void
    {
        $secret = HmacSecret::generate();
        $resolver = new VerifiedWebhookResolver(new WebhookReceiver(new FixedKeys($secret), Tolerance::ofSeconds(60)), new NativeClock());

        self::assertCount(1, $resolver->resolve($this->request($secret, new DateTimeImmutable()), $this->argument(VerifiedWebhook::class)));
    }

    public function testARefusedDeliveryIsUnauthorized(): void
    {
        $secret = HmacSecret::generate();

        try {
            $this->resolver($secret, new DateTimeImmutable('2026-11-03 12:00'))->resolve($this->request($secret, new DateTimeImmutable('2026-11-03 12:06')), $this->argument(VerifiedWebhook::class));
            self::fail('A stale delivery was resolved.');
        } catch (HttpException $httpException) {
            self::assertSame([401, ['X-Rejection-Reason' => 'timestamp_outside_tolerance']], [$httpException->getStatusCode(), $httpException->getHeaders()]);
            self::assertInstanceOf(WebhookRejected::class, $httpException->getPrevious());
            self::assertSame($httpException->getPrevious()->getMessage(), $httpException->getMessage());
        }
    }

    public function testAMalformedDeliveryIsABadRequest(): void
    {
        $secret = HmacSecret::generate();
        $request = $this->request($secret, new DateTimeImmutable('2026-11-03 12:00'));
        $request->headers->remove('webhook-signature');

        try {
            $this->resolver($secret, new DateTimeImmutable('2026-11-03 12:00'))->resolve($request, $this->argument(VerifiedWebhook::class));
            self::fail('A delivery without signature was resolved.');
        } catch (BadRequestHttpException $badRequestHttpException) {
            self::assertInstanceOf(InvalidWebhookValue::class, $badRequestHttpException->getPrevious());
            self::assertSame($badRequestHttpException->getPrevious()->getMessage(), $badRequestHttpException->getMessage());
        }
    }

    private function resolver(HmacSecret $secret, DateTimeImmutable $now): VerifiedWebhookResolver
    {
        $clock = new readonly class($now) implements ClockInterface {
            public function __construct(
                private DateTimeImmutable $now,
            ) {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };

        return new VerifiedWebhookResolver(new WebhookReceiver(new FixedKeys($secret), Tolerance::ofSeconds(300)), $clock);
    }

    private function request(HmacSecret $secret, DateTimeImmutable $signedAt): Request
    {
        $request = Request::create('/webhooks', 'POST', content: self::BODY);
        $request->headers->add(new Signer()->sign(WebhookId::fromString('msg_1'), WebhookTimestamp::fromDateTime($signedAt), self::BODY, $secret)->toArray());

        return $request;
    }

    private function argument(?string $type): ArgumentMetadata
    {
        return new ArgumentMetadata('webhook', $type, false, false, null);
    }
}
