<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Says a migration is reshaping the tables: set before its first DDL, cleared
 * after its last. While it's set, the tables and the stored schemas don't
 * match, so reads and writes refuse to run. A migration that crashed leaves
 * it set, until a migration finishes the job.
 */
final class MigrationMarker
{
    public const TABLE = 'migration_state';

    /** The id of the one row the table ever holds. */
    private const ROW = 1;

    /** Whether a check found no migration running: never checked again after that. */
    private bool $settled = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** Creates the marker's table, unless it exists. */
    public function ensureTable(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            return;
        }

        $table = new Table(self::TABLE);
        $table->addColumn('id', Types::SMALLINT);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $schemaManager->createTable($table);
    }

    /** Sets the marker. One left by a crashed migration keeps its start time. */
    public function set(): void {
        $this->connection->executeStatement(
            sprintf('INSERT IGNORE INTO %s (id, started_at) VALUES (?, ?)', self::TABLE),
            [self::ROW, new DateTimeImmutable('now', new DateTimeZone('UTC'))],
            [Types::SMALLINT, Types::DATETIME_IMMUTABLE],
        );
    }

    public function clear(): void {
        $this->connection->delete(self::TABLE, ['id' => self::ROW]);
    }

    /**
     * Throws while the marker is set. Once a check finds it clear, later ones
     * don't query again. A database no migration ever ran on has no marker.
     */
    public function assertSettled(): void {
        if ($this->settled) {
            return;
        }

        try {
            $startedAt = $this->connection->fetchOne(sprintf('SELECT started_at FROM %s WHERE id = ?', self::TABLE), [self::ROW]);
        } catch (TableNotFoundException) {
            $startedAt = false;
        }

        if ($startedAt !== false) {
            throw new MigrationUnfinished($startedAt);
        }

        $this->settled = true;
    }
}
