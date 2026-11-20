<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Override;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\DbalProcessedWebhooks;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookTable;
use PHPUnit\Framework\TestCase;

/**
 * The processed ids on a real server read from the environment: two ids that differ by their case are both claimed,
 * an id is claimed once, and each receiver claims its ids apart.
 */
abstract class ServerProcessedWebhooksTestCase extends TestCase
{
    private ?Connection $connection = null;

    /**
     * The variable of the server's URL.
     */
    abstract protected function variable(): string;

    public function testIdsThatDifferByTheirCaseAreTwoIds(): void
    {
        $processed = new DbalProcessedWebhooks($this->connection());
        $at = new DateTimeImmutable('2026-11-10 12:00 UTC');

        self::assertTrue($processed->claim(WebhookId::fromString('msg_A'), $at));
        self::assertTrue($processed->claim(WebhookId::fromString('msg_a'), $at));
        self::assertFalse($processed->claim(WebhookId::fromString('msg_a'), $at));
        self::assertFalse($processed->claim(WebhookId::fromString('msg_A'), $at));
    }

    public function testEachReceiverClaimsItsIdsApart(): void
    {
        $processed = new DbalProcessedWebhooks($this->connection(), 'shop');
        $at = new DateTimeImmutable('2026-11-10 12:00 UTC');

        self::assertTrue($processed->forReceiver('partner')->claim(WebhookId::fromString('msg_1'), $at));
        self::assertTrue($processed->claim(WebhookId::fromString('msg_1'), $at));
        self::assertFalse($processed->claim(WebhookId::fromString('msg_1'), $at));
        self::assertTrue($processed->forReceiver('Shop')->claim(WebhookId::fromString('msg_1'), $at), 'Two receivers that differ by their case are two receivers.');
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->connection?->executeStatement('DROP TABLE IF EXISTS '.ProcessedWebhookTable::NAME);
        $this->connection?->close();
        $this->connection = null;
    }

    protected function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        $url = getenv($this->variable());

        if (!\is_string($url) || '' === $url) {
            self::markTestSkipped(\sprintf('Set %s to run this test against a real server.', $this->variable()));
        }

        $connection = DriverManager::getConnection(new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql'])->parse($url));
        $this->connection = $connection;
        $connection->executeStatement('DROP TABLE IF EXISTS '.ProcessedWebhookTable::NAME);
        $manager = $connection->createSchemaManager();

        foreach (ProcessedWebhookTable::configure(Schema::editor()->create(), $connection->getDatabasePlatform())->getTables() as $table) {
            $manager->createTable($table);
        }

        return $connection;
    }
}
