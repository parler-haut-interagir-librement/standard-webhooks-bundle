<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Unit\Infrastructure;

use ArrayObject;
use DateTimeImmutable;
use LogicException;
use Phil\StandardWebhooks\Exception\WebhookException;
use Phil\StandardWebhooks\FixedKeys;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooks\Signer;
use Phil\StandardWebhooks\Tolerance;
use Phil\StandardWebhooks\WebhookHeaders;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooks\WebhookReceiver;
use Phil\StandardWebhooksBundle\Application\SigningKeys;
use Phil\StandardWebhooksBundle\Infrastructure\Http\DeliveryFailed;
use Phil\StandardWebhooksBundle\Infrastructure\Http\WebhookSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(WebhookSender::class)]
#[CoversClass(DeliveryFailed::class)]
final class WebhookSenderTest extends TestCase
{
    private const string BODY = '{"type":"invoice.paid","timestamp":"2026-10-31T12:00:00Z","data":{}}';

    // The tests send to hooks.example, which resolves to nothing: those not about the private-network guard allow
    // private networks, so that no lookup decides them.

    public function testADeliveryIsSignedAndPosted(): void
    {
        $current = HmacSecret::generate();
        $old = HmacSecret::generate();
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = [$method, $url, $options];

            return new MockResponse('', ['http_code' => 204]);
        });
        $now = new DateTimeImmutable('2026-11-03 12:00');
        $id = new WebhookSender($client, new Signer(), new SigningKeys($current, $old), $this->clock($now), 2.5, allowPrivateNetworks: true)->send('https://hooks.example/hook', self::BODY, headers: ['X-Tenant' => 'a']);

        self::assertMatchesRegularExpression('/^msg_/', $id->value);
        self::assertCount(1, $sent);
        [$method, $url, $options] = $sent[0];
        self::assertSame(['POST', 'https://hooks.example/hook', self::BODY, 0, 2.5], [$method, $url, $options['body'], $options['max_redirects'], $options['timeout']]);
        $headers = $this->headers($options['headers']);
        self::assertSame(['application/json', 'a', $id->value, (string) $now->getTimestamp()], [$headers['content-type'], $headers['x-tenant'], $headers['webhook-id'], $headers['webhook-timestamp']]);
        self::assertSame(2, substr_count($headers['webhook-signature'], 'v1,'));

        $received = new WebhookReceiver(new FixedKeys($old), Tolerance::ofSeconds(60))->receive(WebhookHeaders::fromStrings($headers['webhook-id'], $headers['webhook-timestamp'], $headers['webhook-signature']), self::BODY, $now);
        self::assertSame($id->value, $received->id->value);
    }

    public function testARetryKeepsItsIdAndTheSignatureHeadersWin(): void
    {
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        });
        $id = WebhookId::fromString('msg_1');

        self::assertSame($id, new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true)->send('https://hooks.example/hook', self::BODY, $id, ['webhook-id' => 'msg_forged', 'Content-Type' => 'text/plain']));
        $headers = $this->headers($sent[0]['headers']);
        self::assertSame(['msg_1', 'text/plain', 10.0], [$headers['webhook-id'], $headers['content-type'], $sent[0]['timeout']]);
        self::assertEqualsWithDelta(time(), (int) $headers['webhook-timestamp'], 5);
    }

    public function testAnAnswerOutsideTwoHundredsIsAFailure(): void
    {
        foreach ([199 => false, 200 => true, 299 => true, 300 => false, 500 => false] as $status => $acknowledged) {
            $sender = new WebhookSender(new MockHttpClient(new MockResponse('', ['http_code' => $status])), new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true);

            try {
                $sender->send('https://hooks.example/hook', self::BODY, WebhookId::fromString('msg_1'));
                self::assertTrue($acknowledged, (string) $status);
            } catch (DeliveryFailed $deliveryFailed) {
                self::assertFalse($acknowledged, (string) $status);
                self::assertSame([$status, 'msg_1', \sprintf('The webhook msg_1 was answered %d.', $status), null, $status >= 500], [$deliveryFailed->status, $deliveryFailed->id->value, $deliveryFailed->getMessage(), $deliveryFailed->getPrevious(), $deliveryFailed->retryable]);
                self::assertSame(0, $deliveryFailed->getCode());
                self::assertInstanceOf(WebhookException::class, $deliveryFailed);
            }
        }
    }

    public function testARedirectIsNotFollowed(): void
    {
        /** @var ArrayObject<int, string> $sent */
        $sent = new ArrayObject();
        $client = new MockHttpClient(static function (string $method, string $url) use ($sent): MockResponse {
            $sent->append($url);

            return 1 === \count($sent)
                ? new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://93.184.215.15/elsewhere'])
                : new MockResponse('', ['http_code' => 204]);
        });

        try {
            new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true)->send('https://hooks.example/hook', self::BODY, WebhookId::fromString('msg_1'));
            self::fail('A redirect was followed.');
        } catch (DeliveryFailed $deliveryFailed) {
            self::assertSame(302, $deliveryFailed->status);
        }

        self::assertSame(['https://hooks.example/hook'], $sent->getArrayCopy());
    }

    public function testNoAnswerIsAFailure(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Connection refused'));

        try {
            new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true)->send('https://hooks.example/hook', self::BODY, WebhookId::fromString('msg_1'));
            self::fail('An unanswered delivery succeeded.');
        } catch (DeliveryFailed $deliveryFailed) {
            self::assertSame([null, 'The webhook msg_1 was not answered: Connection refused', 0, true], [$deliveryFailed->status, $deliveryFailed->getMessage(), $deliveryFailed->getCode(), $deliveryFailed->retryable]);
            self::assertInstanceOf(TransportException::class, $deliveryFailed->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateAddresses(): iterable
    {
        yield 'cloud metadata' => ['169.254.169.254'];
        yield 'loopback' => ['127.0.0.1'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'private network' => ['10.0.0.7'];
    }

    #[DataProvider('privateAddresses')]
    public function testAHostThatResolvesToAPrivateAddressIsNotSentTo(string $ip): void
    {
        /** @var ArrayObject<int, string> $sent */
        $sent = new ArrayObject();
        // The guard checks the host before the request — hooks.example resolves to nothing, which it refuses too — and
        // the address the client connects to, here a private one.
        $client = new MockHttpClient(static function (string $method, string $url) use ($sent, $ip): MockResponse {
            $sent->append($url);

            return new MockResponse('', ['http_code' => 204, 'primary_ip' => $ip]);
        });

        try {
            new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock())->send('https://hooks.example/hook', self::BODY, WebhookId::fromString('msg_1'));
            self::fail($ip.' was sent to.');
        } catch (DeliveryFailed $deliveryFailed) {
            self::assertNull($deliveryFailed->status);
            self::assertStringContainsString('is blocked for', $deliveryFailed->getMessage());
        }

        self::assertSame('msg_1', new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true)->send('https://hooks.example/hook', self::BODY, WebhookId::fromString('msg_1'))->value, 'Allowed by the configuration, a private address is sent to.');
    }

    public function testOnlyAnHttpsUrlIsSentToUnlessPlainHttpIsAllowed(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 204]));
        $strict = new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true);
        $lenient = new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true, allowPlainHttp: true);

        foreach ([[$strict, 'http://hooks.example/hook', 'is not an https URL to a host name'], [$strict, 'ftp://hooks.example/hook', 'is not an https URL to a host name'], [$strict, '/hook', 'is not an https URL to a host name'], [$lenient, 'gopher://hooks.example/hook', 'is not an http or https URL to a host name'], [$lenient, 'file:///etc/passwd', 'is not an http or https URL to a host name']] as [$sender, $url, $reason]) {
            try {
                $sender->send($url, self::BODY, WebhookId::fromString('msg_1'));
                self::fail($url.' was sent to.');
            } catch (DeliveryFailed $deliveryFailed) {
                self::assertSame([null, false, \sprintf("The webhook msg_1 was not sent: the receiver's URL %s.", $reason)], [$deliveryFailed->status, $deliveryFailed->retryable, $deliveryFailed->getMessage()]);
            }
        }

        self::assertSame('msg_2', $lenient->send('HTTP://hooks.example/hook', self::BODY, WebhookId::fromString('msg_2'))->value);
        self::assertSame('msg_3', $strict->send('HTTPS://hooks.example/hook', self::BODY, WebhookId::fromString('msg_3'))->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableAddresses(): iterable
    {
        // parse_url() read the scheme only: each of these went on to the HTTP client, and, with private networks
        // allowed, to its host.
        yield 'a user and a password' => ['https://user:secret@hooks.example/hook'];
        yield 'credentials before the host' => ['https://hooks.example@evil.example/hook'];
        yield 'an IP address' => ['https://127.0.0.1/hook'];
        yield 'an IP address in decimal' => ['https://2130706433/hook'];
        yield 'an IPv6 address' => ['https://[::1]/hook'];
        yield 'a host of one name' => ['https://metadata/computeMetadata/v1/'];
        yield 'port 0' => ['https://hooks.example:0/hook'];
        yield 'no host' => ['https:///hook'];
    }

    #[DataProvider('unreadableAddresses')]
    public function testAnAddressThatIsNotAHostNameIsNeverSentTo(string $url): void
    {
        $sent = new ArrayObject();
        $client = new MockHttpClient(static function (string $method, string $url) use ($sent): MockResponse {
            $sent->append($url);

            return new MockResponse('', ['http_code' => 204]);
        });

        foreach ([false, true] as $plainHttp) {
            try {
                new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true, allowPlainHttp: $plainHttp)->send($url, self::BODY, WebhookId::fromString('msg_1'));
                self::fail($url.' was sent to.');
            } catch (DeliveryFailed $deliveryFailed) {
                self::assertSame([null, false], [$deliveryFailed->status, $deliveryFailed->retryable]);
                self::assertStringContainsString('to a host name', $deliveryFailed->getMessage());
            }
        }

        self::assertCount(0, $sent);
    }

    public function testTheAddressIsSentInItsCanonicalForm(): void
    {
        $sent = new ArrayObject();
        $client = new MockHttpClient(static function (string $method, string $url) use ($sent): MockResponse {
            $sent->append($url);

            return new MockResponse('', ['http_code' => 204]);
        });

        new WebhookSender($client, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock(), allowPrivateNetworks: true)->send('HTTPS://Hooks.Bücher.Example.:443/a/%2e%2e/hook', self::BODY);

        self::assertSame(['https://hooks.xn--bcher-kva.example/hook'], $sent->getArrayCopy());
    }

    public function testWithoutAClientNothingIsSent(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Sending webhooks needs an HTTP client: enable framework.http_client.');

        new WebhookSender(null, new Signer(), new SigningKeys(HmacSecret::generate()), new NativeClock())->send('https://hooks.example/hook', self::BODY);
    }

    /**
     * @param mixed $lines the request's normalized header lines
     *
     * @return array<string, string>
     */
    private function headers(mixed $lines): array
    {
        self::assertIsArray($lines);
        $headers = [];

        foreach ($lines as $line) {
            self::assertIsString($line);
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    private function clock(DateTimeImmutable $now): ClockInterface
    {
        return new readonly class($now) implements ClockInterface {
            public function __construct(
                private DateTimeImmutable $now,
            ) {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
