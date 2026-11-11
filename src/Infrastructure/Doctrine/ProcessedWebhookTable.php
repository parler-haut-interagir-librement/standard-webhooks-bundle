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
 * The table of processed ids: the id, its primary key, and when it was processed, indexed for the purge. Two ids
 * that differ by their case are two ids: on MySQL and MariaDB, whose default collations ignore the case, the id is
 * stored in ASCII and compared by its bytes, `ascii_bin`; the other platforms compare strings exactly already.
 */
final class ProcessedWebhookTable
{
    public const string NAME = 'phil_standard_webhooks_processed';

    public static function configure(Schema $schema, ?AbstractPlatform $platform = null): Schema
    {
        if ($schema->hasTable(self::NAME)) {
            return $schema;
        }

        $id = Column::editor()->setUnquotedName('id')->setTypeName(Types::STRING)->setLength(WebhookId::MAX_LENGTH);

        if ($platform instanceof AbstractMySQLPlatform) {
            $id->setCharset('ascii')->setCollation('ascii_bin');
        }

        return $schema->edit()->addTable(Table::editor()
            ->setUnquotedName(self::NAME)
            ->setColumns(
                $id->create(),
                Column::editor()->setUnquotedName('processed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->setIndexes(Index::editor()->setUnquotedName(self::NAME.'_processed_at')->setUnquotedColumnNames('processed_at')->create())
            ->create())->create();
    }
}
