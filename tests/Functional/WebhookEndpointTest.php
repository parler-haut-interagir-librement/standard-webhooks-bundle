<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Phil\StandardWebhooks\HmacSecret;
use Phil\StandardWebhooks\WebhookHeaders;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookTable;
use Phil\StandardWebhooksBundle\Infrastructure\Http\DeliveryFailed;
use Phil\StandardWebhooksBundle\Infrastructure\Http\WebhookSender;
use Phil\StandardWebhooksBundle\PhilStandardWebhooksBundle;
use Phil\StandardWebhooksBundle\Tests\Functional\App\RotatingKeyProvider;
use Phil\StandardWebhooksBundle\Tests\Functional\App\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Signed deliveries through the application's endpoint: verified in both schemes, a rotation picked up with one
 * reload, a retried delivery acknowledged without being processed twice, and each kind of refusal answered
 * before anything is processed.
 */
#[CoversClass(PhilStandardWebhooksBundle::class)]
final class WebhookEndpointTest extends FunctionalTestCase
{
    private const string BODY = '{"type":"invoice.paid","timestamp":"2026-10-31T12:00:00Z","data":{"id":"inv_1"},"audience":"receiver-1"}';

    public function testADeliverySignedWithTheSharedSecretIsProcessed(): void
    {
        $response = $this->deliver($this->sign(self::BODY, HmacSecret::fromString(TestKernel::HMAC_SECRET)), self::BODY);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['msg_1'], $this->claimed());
        self::assertSame(0, $this->keys()->refreshes);
    }

    /**
     * Every attempt carries the same id: the second one is acknowledged, and not processed again.
     */
    public function testARetriedDeliveryIsAcknowledgedWithoutBeingProcessedTwice(): void
    {
        $headers = $this->sign(self::BODY, HmacSecret::fromString(TestKernel::HMAC_SECRET));

        self::assertSame(204, $this->deliver($headers, self::BODY)->getStatusCode());
        self::assertSame(204, $this->deliver($headers, self::BODY)->getStatusCode());
        self::assertCount(1, $this->claimed());
    }

    /**
     * The sender signs with a key the receiver has not cached yet: the receiver reloads its keys once, and accepts.
     */
    public function testADeliverySignedWithANewKeyIsAcceptedAfterOneReload(): void
    {
        $response = $this->deliver($this->sign(self::BODY, TestKernel::senderKey()), self::BODY);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(1, $this->keys()->refreshes);
        self::assertCount(1, $this->claimed());
    }

    public function testABodyChangedAfterSigningIsRefused(): void
    {
        $headers = $this->sign(self::BODY, HmacSecret::fromString(TestKernel::HMAC_SECRET));

        $response = $this->deliver($headers, str_replace('inv_1', 'inv_2', self::BODY));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('no_valid_signature', $response->headers->get('X-Rejection-Reason'));
        self::assertSame(1, $this->keys()->refreshes);
        self::assertSame([], $this->claimed());
    }

    /**
     * A genuine signature does not rescue a stale delivery, and new keys would not make it fresher.
     */
    public function testADeliveryOneSecondPastTheToleranceIsRefusedWithoutReload(): void
    {
        $signedAt = new DateTimeImmutable(TestKernel::NOW)->modify(\sprintf('-%d seconds', TestKernel::TOLERANCE + 1));

        $response = $this->deliver($this->sign(self::BODY, HmacSecret::fromString(TestKernel::HMAC_SECRET), signedAt: $signedAt), self::BODY);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('timestamp_outside_tolerance', $response->headers->get('X-Rejection-Reason'));
        self::assertSame(0, $this->keys()->refreshes);
        self::assertSame([], $this->claimed());
    }

    public function testADeliveryForAnotherAudienceIsRefused(): void
    {
        $body = str_replace('receiver-1', 'receiver-2', self::BODY);

        $response = $this->deliver($this->sign($body, HmacSecret::fromString(TestKernel::HMAC_SECRET)), $body);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('audience_mismatch', $response->headers->get('X-Rejection-Reason'));
        self::assertSame([], $this->claimed());
    }

    public function testADeliveryWithoutItsSignatureIsMalformed(): void
    {
        $headers = $this->sign(self::BODY, HmacSecret::fromString(TestKernel::HMAC_SECRET));
        unset($headers[WebhookHeaders::SIGNATURE]);

        self::assertSame(400, $this->deliver($headers, self::BODY)->getStatusCode());
        self::assertSame([], $this->claimed());
    }

    /**
     * A verified delivery of a type the receiver does not process is never acknowledged, so the sender retries it.
     */
    public function testAnUnknownTypeIsNotAcknowledged(): void
    {
        $body = str_replace('invoice.paid', 'invoice.voided', self::BODY);

        self::assertSame(422, $this->deliver($this->sign($body, HmacSecret::fromString(TestKernel::HMAC_SECRET)), $body)->getStatusCode());
        self::assertSame([], $this->claimed());
    }

    protected function setUp(): void
    {
        parent::setUp();
        // The table comes from the bundle's schema listener, as a migration would create it.
        $entityManager = $this->service('doctrine.orm.entity_manager', EntityManagerInterface::class);
        foreach (new SchemaTool($entityManager)->getCreateSchemaSql([]) as $sql) {
            $this->connection()->executeStatement($sql);
        }
    }

    public function testWhatTheSenderSendsTheReceiverProcesses(): void
    {
        $sender = $this->service(WebhookSender::class, WebhookSender::class);
        $id = $sender->send('http://localhost/webhooks', self::BODY);

        self::assertSame([$id->value], $this->claimed());
        self::assertSame(1, $this->keys()->refreshes);
        self::assertSame($id, $sender->send('http://localhost/webhooks', self::BODY, $id));
        self::assertSame([$id->value], $this->claimed());

        try {
            $sender->send('http://localhost/webhooks', str_replace('invoice.paid', 'invoice.voided', self::BODY), WebhookId::fromString('msg_2'));
            self::fail('An unprocessed delivery was acknowledged.');
        } catch (DeliveryFailed $deliveryFailed) {
            self::assertSame([422, 'msg_2'], [$deliveryFailed->status, $deliveryFailed->id->value]);
        }
    }

    /**
     * @return list<string>
     */
    private function claimed(): array
    {
        /** @var list<string> */
        return $this->connection()->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', ProcessedWebhookTable::NAME));
    }

    private function connection(): Connection
    {
        return $this->service('doctrine.dbal.default_connection', Connection::class);
    }

    private function keys(): RotatingKeyProvider
    {
        return $this->service(RotatingKeyProvider::class, RotatingKeyProvider::class);
    }
}
