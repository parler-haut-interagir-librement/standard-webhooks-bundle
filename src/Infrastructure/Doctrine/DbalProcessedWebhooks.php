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
use InvalidArgumentException;
use Override;
use Phil\StandardWebhooks\ProcessedWebhookRepository;
use Phil\StandardWebhooks\WebhookId;

/**
 * The processed ids of one receiver in their table, claimed by an insert that does nothing when the receiver's id is
 * there: `ON CONFLICT DO NOTHING` on PostgreSQL, `INSERT IGNORE` on MySQL and MariaDB, `INSERT OR IGNORE` on SQLite —
 * so that a second attempt does not abort the processing transaction; elsewhere, a plain insert whose unique violation
 * is a `false`.
 *
 * The receiver's name keys its ids apart from those of another receiver: an application with several senders, each
 * verified by its own `WebhookReceiver`, claims each sender's ids with `forReceiver()`, so that one sender cannot have
 * the event of another ignored by sending its id first.
 */
final readonly class DbalProcessedWebhooks implements ProcessedWebhookRepository
{
    /**
     * @param string $receiver the name of the receiver whose ids this claims: 1 to 64 letters, digits, `_`, `.` or `-`
     *
     * @throws InvalidArgumentException when the name is not one
     */
    public function __construct(
        private Connection $connection,
        public string $receiver = ProcessedWebhookTable::DEFAULT_RECEIVER,
    ) {
        if (1 !== preg_match(ProcessedWebhookTable::RECEIVER_PATTERN, $receiver)) {
            throw new InvalidArgumentException(\sprintf('The name of a receiver holds 1 to %d letters, digits, "_", "." or "-"; "%s" does not.', ProcessedWebhookTable::RECEIVER_MAX_LENGTH, addcslashes(substr($receiver, 0, 100), "\x00..\x1F\x7F")));
        }
    }

    /**
     * The ids of another receiver, on the same connection.
     *
     * @throws InvalidArgumentException when the name is not one
     */
    public function forReceiver(string $receiver): self
    {
        return new self($this->connection, $receiver);
    }

    #[Override]
    public function claim(WebhookId $id, DateTimeImmutable $processedAt): bool
    {
        try {
            // One row inserted, or none when the id was there.
            return 0 < $this->connection->executeStatement(
                self::insert($this->connection->getDatabasePlatform()),
                [$this->receiver, $id->value, $processedAt->setTimezone(new DateTimeZone('UTC'))],
                [Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE],
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Forgets the ids processed before a moment, those of every receiver: one that outlasts the tolerance and the
     * senders' retries.
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
        $values = \sprintf('%s (receiver, id, processed_at) VALUES (?, ?, ?)', ProcessedWebhookTable::NAME);

        return match (true) {
            $platform instanceof PostgreSQLPlatform => 'INSERT INTO '.$values.' ON CONFLICT (receiver, id) DO NOTHING',
            $platform instanceof AbstractMySQLPlatform => 'INSERT IGNORE INTO '.$values,
            $platform instanceof SQLitePlatform => 'INSERT OR IGNORE INTO '.$values,
            default => 'INSERT INTO '.$values,
        };
    }
}
