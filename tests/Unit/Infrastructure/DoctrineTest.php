<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use InvalidArgumentException;
use Phil\StandardWebhooks\WebhookId;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\DbalProcessedWebhooks;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookSchemaListener;
use Phil\StandardWebhooksBundle\Infrastructure\Doctrine\ProcessedWebhookTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(DbalProcessedWebhooks::class)]
#[CoversClass(ProcessedWebhookTable::class)]
#[CoversClass(ProcessedWebhookSchemaListener::class)]
final class DoctrineTest extends TestCase
{
    public function testAnIdIsClaimedOnceAndForgottenAfterAWhile(): void
    {
        $connection = $this->connection();
        $processed = new DbalProcessedWebhooks($connection);
        $at = new DateTimeImmutable('2026-11-03 07:00', new DateTimeZone('America/Toronto'));

        self::assertTrue($processed->claim(WebhookId::fromString('msg_1'), $at));
        self::assertFalse($processed->claim(WebhookId::fromString('msg_1'), $at));
        self::assertTrue($processed->claim(WebhookId::fromString('msg_2'), $at->modify('+1 day')));
        self::assertSame('2026-11-03 12:00:00', $connection->fetchOne('SELECT processed_at FROM phil_standard_webhooks_processed WHERE id = ?', ['msg_1']));

        self::assertSame(0, $processed->purge(new DateTimeImmutable('2026-11-03 12:00 UTC')));
        self::assertSame(1, $processed->purge(new DateTimeImmutable('2026-11-03 08:00', new DateTimeZone('America/Toronto'))));
        self::assertSame(['msg_2'], $connection->fetchFirstColumn('SELECT id FROM phil_standard_webhooks_processed'));
    }

    public function testAPlainInsertTurnsAUniqueViolationIntoFalse(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('INSERT INTO phil_standard_webhooks_processed (id, processed_at) VALUES (?, ?)', ['msg_1', '2026-11-03 12:00:00']);
        $connection->executeStatement('INSERT INTO phil_standard_webhooks_processed (receiver, id, processed_at) VALUES (?, ?, ?)', ['partner', 'msg_3', '2026-11-03 12:00:00']);

        $processed = new DbalProcessedWebhooks($connection);
        $plain = new ReflectionProperty(Connection::class, 'platform');
        $plain->setValue($connection, new OraclePlatform());

        self::assertFalse($processed->claim(WebhookId::fromString('msg_1'), new DateTimeImmutable()));
        self::assertTrue($processed->claim(WebhookId::fromString('msg_2'), new DateTimeImmutable()));
        self::assertTrue($processed->claim(WebhookId::fromString('msg_3'), new DateTimeImmutable()), "The id of another receiver is not this one's.");
        self::assertFalse($processed->forReceiver('partner')->claim(WebhookId::fromString('msg_3'), new DateTimeImmutable()));
    }

    public function testEachReceiverClaimsItsIdsApart(): void
    {
        $connection = $this->connection();
        $processed = new DbalProcessedWebhooks($connection, 'shop');
        $partner = $processed->forReceiver('partner.v2-b_1');
        $at = new DateTimeImmutable('2026-11-03 12:00 UTC');

        self::assertSame(['shop', 'partner.v2-b_1', 'default'], [$processed->receiver, $partner->receiver, new DbalProcessedWebhooks($connection)->receiver]);
        self::assertTrue($partner->claim(WebhookId::fromString('msg_1'), $at), 'A sender that sends the id of another first has nothing of it ignored.');
        self::assertTrue($processed->claim(WebhookId::fromString('msg_1'), $at));
        self::assertFalse($processed->claim(WebhookId::fromString('msg_1'), $at));
        self::assertFalse($partner->claim(WebhookId::fromString('msg_1'), $at));
        self::assertSame([['partner.v2-b_1', 'msg_1'], ['shop', 'msg_1']], $connection->fetchAllNumeric('SELECT receiver, id FROM phil_standard_webhooks_processed ORDER BY receiver'));
        self::assertSame(2, $processed->purge($at->modify('+1 second')), 'The purge forgets the ids of every receiver.');
    }

    public function testTheMessageQuotesAtMostTheFirstHundredBytesOfTheName(): void
    {
        try {
            new DbalProcessedWebhooks($this->connection(false), str_repeat('a', 150));
            self::fail('A name of 150 bytes was taken for the name of a receiver.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame(\sprintf('The name of a receiver holds 1 to 64 letters, digits, "_", "." or "-"; "%s" does not.', str_repeat('a', 100)), $invalidArgumentException->getMessage());
        }
    }

    public function testTheNameOfAReceiverIsBounded(): void
    {
        $connection = $this->connection(false);
        self::assertSame(str_repeat('a', 64), new DbalProcessedWebhooks($connection, str_repeat('a', 64))->receiver);

        foreach (['', str_repeat('a', 65), 'shop partner', "shop\n", 'café', 'a/b'] as $name) {
            try {
                new DbalProcessedWebhooks($connection, $name);
                self::fail(\sprintf('"%s" was taken for the name of a receiver.', $name));
            } catch (InvalidArgumentException $invalidArgumentException) {
                self::assertStringStartsWith('The name of a receiver holds 1 to 64 letters, digits, "_", "." or "-"; "', $invalidArgumentException->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"shop\n" does not.');

        new DbalProcessedWebhooks($connection)->forReceiver("shop\n");
    }

    public function testEachPlatformIgnoresADuplicateItsOwnWay(): void
    {
        $values = 'phil_standard_webhooks_processed (receiver, id, processed_at) VALUES (?, ?, ?)';

        self::assertSame([
            'INSERT INTO '.$values.' ON CONFLICT (receiver, id) DO NOTHING',
            'INSERT IGNORE INTO '.$values,
            'INSERT IGNORE INTO '.$values,
            'INSERT OR IGNORE INTO '.$values,
            'INSERT INTO '.$values,
        ], array_map(DbalProcessedWebhooks::insert(...), [new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform(), new SQLitePlatform(), new OraclePlatform()]));
    }

    /**
     * Ids differ by their case, `msg_A` and `msg_a`: MySQL and MariaDB compare them so only in a binary collation,
     * which the column asks for there; the other platforms compare strings exactly already.
     */
    public function testTheIdsAreComparedByTheirBytesOnMysql(): void
    {
        foreach ([new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $sql = implode(';', $platform->getCreateTablesSQL(ProcessedWebhookTable::configure(Schema::editor()->create(), $platform)->getTables()));

            self::assertStringContainsString(' id VARCHAR(255) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`', $sql, $platform::class);
            self::assertStringContainsString("receiver VARCHAR(64) CHARACTER SET ascii DEFAULT 'default' NOT NULL COLLATE `ascii_bin`", $sql, $platform::class);
        }

        foreach ([new PostgreSQLPlatform(), new SQLitePlatform(), null] as $platform) {
            $table = ProcessedWebhookTable::configure(Schema::editor()->create(), $platform)->getTable(ProcessedWebhookTable::NAME);

            foreach (['id', 'receiver'] as $name) {
                self::assertSame([null, null], [$table->getColumn($name)->getCharset(), $table->getColumn($name)->getCollation()]);
            }
        }
    }

    public function testTheListenerAsksForTheCollationOfItsConnection(): void
    {
        $connection = $this->connection(false);
        $platform = new ReflectionProperty(Connection::class, 'platform');
        $platform->setValue($connection, new MySQLPlatform());

        $event = new GenerateSchemaEventArgs($this->entityManager($connection), Schema::editor()->create());

        new ProcessedWebhookSchemaListener($connection)->postGenerateSchema($event);

        self::assertSame('ascii_bin', $event->getSchema()->getTable(ProcessedWebhookTable::NAME)->getColumn('id')->getCollation());
        self::assertSame('ascii_bin', $event->getSchema()->getTable(ProcessedWebhookTable::NAME)->getColumn('receiver')->getCollation());
    }

    public function testTheTableJoinsTheSchemaOfItsConnection(): void
    {
        $connection = $this->connection(false);
        $other = $this->connection(false);
        $listener = new ProcessedWebhookSchemaListener($connection);

        $schema = new Schema();
        $ignored = new GenerateSchemaEventArgs($this->entityManager($other), $schema);
        $listener->postGenerateSchema($ignored);
        self::assertSame($schema, $ignored->getSchema(), 'the schema of another connection is left as it was');
        self::assertFalse($schema->hasTable(ProcessedWebhookTable::NAME));

        $event = new GenerateSchemaEventArgs($this->entityManager($connection), $schema);

        $listener->postGenerateSchema($event);

        $schema = $event->getSchema();
        $table = $schema->getTable(ProcessedWebhookTable::NAME);
        self::assertSame([255, true, 'datetime_immutable'], [$table->getColumn('id')->getLength(), $table->getColumn('processed_at')->getNotnull(), Type::lookupName($table->getColumn('processed_at')->getType())]);
        self::assertSame([64, true, 'default'], [$table->getColumn('receiver')->getLength(), $table->getColumn('receiver')->getNotnull(), $table->getColumn('receiver')->getDefault()]);
        self::assertSame(['receiver', 'id'], array_map(static fn (object $name): string => $name->toString(), $table->getPrimaryKeyConstraint()?->getColumnNames() ?? []));
        self::assertTrue($table->hasIndex('phil_standard_webhooks_processed_processed_at'));
        self::assertSame($schema, ProcessedWebhookTable::configure($schema));
        self::assertCount(1, $schema->getTables());
    }

    private function connection(bool $withTable = true): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        if ($withTable) {
            foreach ($connection->getDatabasePlatform()->getCreateTablesSQL(ProcessedWebhookTable::configure(new Schema())->getTables()) as $sql) {
                $connection->executeStatement($sql);
            }
        }

        return $connection;
    }

    private function entityManager(Connection $connection): EntityManagerInterface
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }
}
