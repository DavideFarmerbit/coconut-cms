<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Which migration steps a database has applied, by id: what decides which
 * ones are still pending. Written in the same transaction that moves a
 * migration's data, so a step is recorded exactly when its changes are.
 */
final readonly class MigrationLog
{
    public const TABLE = 'migration_steps';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /** Creates the log's table, unless it exists. */
    public function ensureTable(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            return;
        }

        $table = new Table(self::TABLE);
        $table->addColumn('id', Types::STRING, ['length' => 255]);
        $table->addColumn('applied_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $schemaManager->createTable($table);
    }

    /** @return list<string> Every applied step's id. */
    public function applied(): array {
        return $this->connection->fetchFirstColumn(sprintf('SELECT id FROM %s', self::TABLE));
    }

    /** @param list<string> $ids Steps just applied. */
    public function record(array $ids): void {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($ids as $id) {
            $this->connection->insert(self::TABLE, ['id' => $id, 'applied_at' => $now], ['applied_at' => Types::DATETIME_IMMUTABLE]);
        }
    }
}
