<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Phil\StandardWebhooks\WebhookId;

/**
 * The table of processed ids: the receiver that verified the delivery and the id, together its primary key, and when it
 * was processed, indexed for the purge. Each receiver, one per sender, claims its ids apart: a sender that guesses the
 * id of another's event cannot have it ignored. Two ids that differ by their case are two ids: on MySQL and MariaDB,
 * whose default collations ignore the case, the receiver and the id are stored in ASCII and compared by their bytes,
 * `ascii_bin`; the other platforms compare strings exactly already.
 */
final class ProcessedWebhookTable
{
    public const string NAME = 'phil_standard_webhooks_processed';

    /** The longest name of a receiver, in bytes. */
    public const int RECEIVER_MAX_LENGTH = 64;

    /** What the name of a receiver holds: 1 to 64 letters, digits, `_`, `.` or `-`. */
    public const string RECEIVER_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/D';

    /** The receiver of the rows written before the receiver joined the key, and of the bundle when none is configured. */
    public const string DEFAULT_RECEIVER = 'default';

    public static function configure(Schema $schema, ?AbstractPlatform $platform = null): Schema
    {
        if ($schema->hasTable(self::NAME)) {
            return $schema;
        }

        $receiver = Column::editor()->setUnquotedName('receiver')->setTypeName(Types::STRING)->setLength(self::RECEIVER_MAX_LENGTH)->setDefaultValue(self::DEFAULT_RECEIVER);
        $id = Column::editor()->setUnquotedName('id')->setTypeName(Types::STRING)->setLength(WebhookId::MAX_LENGTH);

        if ($platform instanceof AbstractMySQLPlatform) {
            $receiver->setCharset('ascii')->setCollation('ascii_bin');
            $id->setCharset('ascii')->setCollation('ascii_bin');
        }

        return $schema->edit()->addTable(Table::editor()
            ->setUnquotedName(self::NAME)
            ->setColumns(
                $receiver->create(),
                $id->create(),
                Column::editor()->setUnquotedName('processed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('receiver', 'id')->create())
            ->setIndexes(Index::editor()->setUnquotedName(self::NAME.'_processed_at')->setUnquotedColumnNames('processed_at')->create())
            ->create())->create();
    }
}
