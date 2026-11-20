<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Integration;

use Override;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\DbalProcessedWebhooks;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookTable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * MySQL 8.4, read from `PHIL_TEST_MYSQL_URL`, whose default collation ignores the case: the id is stored in ASCII and
 * compared by its bytes.
 */
#[CoversClass(DbalProcessedWebhooks::class)]
#[CoversClass(ProcessedWebhookTable::class)]
final class MySqlProcessedWebhooksTest extends ServerProcessedWebhooksTestCase
{
    public function testTheIdIsBinaryOnTheServer(): void
    {
        self::assertSame(['ascii_bin'], $this->connection()->fetchFirstColumn(
            "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'id'",
            [ProcessedWebhookTable::NAME],
        ));
    }

    #[Override]
    protected function variable(): string
    {
        return 'PHIL_TEST_MYSQL_URL';
    }
}
