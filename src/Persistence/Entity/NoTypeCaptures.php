<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use JsonException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;

/**
 * The values of fields set aside when they broke, kept until an admin
 * converts or drops their broken-field record: one row per entity and record,
 * its captures as JSON, by the site inside the entity where its type sits.
 * Not content: nothing queries it, and no native object loads it. Deleting an
 * entity deletes its rows.
 */
final readonly class NoTypeCaptures
{
    public const TABLE = 'notype_captures';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /** Creates the table, unless it exists. The entities table must exist: every row points at an entity. */
    public function ensureTable(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            return;
        }

        $table = new Table(self::TABLE);
        $table->addColumn('entity_id', Types::GUID);
        $table->addColumn('record_id', Types::GUID);
        $table->addColumn('captured', Types::TEXT);
        $table->setPrimaryKey(['entity_id', 'record_id']);
        // The editor counts a record's entities, and dropping a record deletes its rows.
        $table->addIndex(['record_id']);
        $table->addForeignKeyConstraint(DatabaseSchema::ENTITIES, ['entity_id'], ['id'], ['onDelete' => 'CASCADE']);
        $schemaManager->createTable($table);
    }

    /**
     * Keeps entity $entityId's captures for record $recordId.
     * @param array<string, array{values: array<string, mixed>, touched: list<string>, ownedIds: array<string, string>}> $sites
     *        By the site inside the entity: "" for the entity itself.
     * @throws JsonException
     */
    public function keep(string $entityId, string $recordId, array $sites): void {
        $this->connection->insert($this->connection->quoteSingleIdentifier(self::TABLE), [
            'entity_id' => $entityId,
            'record_id' => $recordId,
            'captured' => json_encode($sites, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    /**
     * Entity $entityId's captures for record $recordId, by site; null when it has none.
     * @return array<string, array{values: array<string, mixed>, touched: list<string>, ownedIds: array<string, string>}>|null
     * @throws JsonException
     */
    public function of(string $entityId, string $recordId): ?array {
        $captured = $this->connection->fetchOne(
            sprintf('SELECT captured FROM %s WHERE entity_id = ? AND record_id = ?', $this->connection->quoteSingleIdentifier(self::TABLE)),
            [$entityId, $recordId],
        );

        return $captured === false ? null : json_decode($captured, true, flags: JSON_THROW_ON_ERROR);
    }
}
