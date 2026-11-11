<?php

declare(strict_types=1);

namespace Phil\StandardWebhooksBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Adds the table of processed ids to the schema the ORM generates for its connection, as Symfony's own stores do:
 * `doctrine:migrations:diff` writes the migration that creates it, `doctrine:schema:validate` keeps it.
 */
final readonly class ProcessedWebhookSchemaListener
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        if ($event->getEntityManager()->getConnection() !== $this->connection) {
            return;
        }

        $event->setSchema(ProcessedWebhookTable::configure($event->getSchema(), $this->connection->getDatabasePlatform()));
    }
}
