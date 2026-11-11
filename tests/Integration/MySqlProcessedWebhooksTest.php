<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\DbalProcessedWebhooks;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MySQL 8, whose default collation ignores the case: two ids that differ by it are both claimed.
 *
 *     PHIL_TEST_MYSQL_URL='mysql://root:secret@127.0.0.1:13306/phil' vendor/bin/phpunit packages/standard-webhooks-bundle/tests/Integration
 */
#[CoversClass(DbalProcessedWebhooks::class)]
#[CoversClass(ProcessedWebhookTable::class)]
#[Group('mysql')]
final class MySqlProcessedWebhooksTest extends TestCase
{
    public function testIdsThatDifferByTheirCaseAreTwoIds(): void
    {
        $url = getenv('PHIL_TEST_MYSQL_URL');

        if (!\is_string($url) || '' === $url) {
            self::markTestSkipped('Set PHIL_TEST_MYSQL_URL to run this test against a real server.');
        }

        $connection = DriverManager::getConnection(new DsnParser(['mysql' => 'pdo_mysql'])->parse($url));
        $connection->executeStatement('DROP TABLE IF EXISTS '.ProcessedWebhookTable::NAME);

        try {
            foreach ($connection->getDatabasePlatform()->getCreateTablesSQL(ProcessedWebhookTable::configure(Schema::editor()->create(), $connection->getDatabasePlatform())->getTables()) as $sql) {
                $connection->executeStatement($sql);
            }

            $processed = new DbalProcessedWebhooks($connection);
            $at = new DateTimeImmutable('2026-11-10 12:00 UTC');

            self::assertTrue($processed->claim(WebhookId::fromString('msg_A'), $at));
            self::assertTrue($processed->claim(WebhookId::fromString('msg_a'), $at));
            self::assertFalse($processed->claim(WebhookId::fromString('msg_a'), $at));
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS '.ProcessedWebhookTable::NAME);
        }
    }
}
