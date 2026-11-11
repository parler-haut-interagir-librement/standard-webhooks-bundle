<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Override;
use Phil\StandardWebhooks\ProcessedWebhookRepository;
use Phil\StandardWebhooks\WebhookId;

/**
 * The processed ids in their table, claimed by an insert that does nothing when the id is there: `ON CONFLICT DO
 * NOTHING` on PostgreSQL, `INSERT IGNORE` on MySQL and MariaDB, `INSERT OR IGNORE` on SQLite — so that a second
 * attempt does not abort the processing transaction; elsewhere, a plain insert whose unique violation is a `false`.
 */
final readonly class DbalProcessedWebhooks implements ProcessedWebhookRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function claim(WebhookId $id, DateTimeImmutable $processedAt): bool
    {
        try {
            // One row inserted, or none when the id was there.
            return 0 < $this->connection->executeStatement(
                self::insert($this->connection->getDatabasePlatform()),
                [$id->value, $processedAt->setTimezone(new DateTimeZone('UTC'))],
                [Types::STRING, Types::DATETIME_IMMUTABLE],
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Forgets the ids processed before a moment: one that outlasts the tolerance and the senders' retries.
     *
     * @return int how many were forgotten
     */
    public function purge(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE processed_at < ?', ProcessedWebhookTable::NAME),
            [$before->setTimezone(new DateTimeZone('UTC'))],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    public static function insert(AbstractPlatform $platform): string
    {
        $values = \sprintf('%s (id, processed_at) VALUES (?, ?)', ProcessedWebhookTable::NAME);

        return match (true) {
            $platform instanceof PostgreSQLPlatform => 'INSERT INTO '.$values.' ON CONFLICT (id) DO NOTHING',
            $platform instanceof AbstractMySQLPlatform => 'INSERT IGNORE INTO '.$values,
            $platform instanceof SQLitePlatform => 'INSERT OR IGNORE INTO '.$values,
            default => 'INSERT INTO '.$values,
        };
    }
}
