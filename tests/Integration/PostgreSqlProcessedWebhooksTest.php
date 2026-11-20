<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Integration;

use Override;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\DbalProcessedWebhooks;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookTable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * PostgreSQL 17, read from `PHIL_TEST_POSTGRESQL_URL`, whose default collation is deterministic: the id keeps it.
 */
#[CoversClass(DbalProcessedWebhooks::class)]
#[CoversClass(ProcessedWebhookTable::class)]
final class PostgreSqlProcessedWebhooksTest extends ServerProcessedWebhooksTestCase
{
    #[Override]
    protected function variable(): string
    {
        return 'PHIL_TEST_POSTGRESQL_URL';
    }
}
