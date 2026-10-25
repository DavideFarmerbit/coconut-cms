<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * What the database says about migrations, in one row: the state key of the
 * code it was last migrated to, and whether a migration is reshaping the
 * tables right now. One query answers both "is a migration pending?" and
 * "is one unfinished?".
 *
 * A migration marks itself started before its first DDL and finished after
 * its last. While it's started, the tables and the stored schemas don't
 * match, so reads and writes refuse to run. One that crashed stays started,
 * until a migration finishes the job.
 */
final class MigrationState
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

    /** Creates the table, unless it exists. */
    public function ensureTable(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            return;
        }

        $table = new Table(self::TABLE);
        $table->addColumn('id', Types::SMALLINT);
        $table->addColumn('state_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('migration_started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $schemaManager->createTable($table);
    }

    /**
     * Marks a migration started. One left started by a crash keeps its start
     * time. Only a migration calls it, holding the migration lock, so a plain
     * update-or-insert never races.
     */
    public function start(): void {
        $table = $this->connection->quoteSingleIdentifier(self::TABLE);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $updated = $this->connection->executeStatement(
            sprintf('UPDATE %s SET migration_started_at = COALESCE(migration_started_at, ?) WHERE id = ?', $table),
            [$now, self::ROW],
            [Types::DATETIME_IMMUTABLE, Types::SMALLINT],
        );
        if ($this->exists($updated)) {
            return;
        }

        $this->connection->insert($table, ['id' => self::ROW, 'migration_started_at' => $now], ['id' => Types::SMALLINT, 'migration_started_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Marks the migration finished, the database now matching the code whose
     * state key is $stateKey. Holds the migration lock too.
     */
    public function finish(string $stateKey): void {
        $table = $this->connection->quoteSingleIdentifier(self::TABLE);

        $updated = $this->connection->executeStatement(
            sprintf('UPDATE %s SET state_key = ?, migration_started_at = NULL WHERE id = ?', $table),
            [$stateKey, self::ROW],
            [Types::STRING, Types::SMALLINT],
        );
        if ($this->exists($updated)) {
            return;
        }

        $this->connection->insert($table, ['id' => self::ROW, 'state_key' => $stateKey], ['id' => Types::SMALLINT, 'state_key' => Types::STRING]);
    }

    /**
     * Whether the row exists, after an update touched $updated rows. MySQL
     * counts only rows it changed, so a matching row left as it was counts 0.
     */
    private function exists(int|string $updated): bool {
        return (int) $updated > 0 || $this->connection->fetchOne(sprintf('SELECT 1 FROM %s WHERE id = ?', $this->connection->quoteSingleIdentifier(self::TABLE)), [self::ROW]) !== false;
    }

    /**
     * The state key the database was last migrated to, and when the migration
     * running now started, both null when there's none. A database no
     * migration ever ran on has neither.
     * @return array{stateKey: ?string, startedAt: ?string}
     */
    public function read(): array {
        try {
            $row = $this->connection->fetchAssociative(sprintf('SELECT state_key, migration_started_at FROM %s WHERE id = ?', $this->connection->quoteSingleIdentifier(self::TABLE)), [self::ROW]);
        } catch (TableNotFoundException) {
            $row = false;
        }

        return ['stateKey' => $row['state_key'] ?? null, 'startedAt' => $row['migration_started_at'] ?? null];
    }

    /**
     * Throws while a migration is started. Once a check finds none, later
     * ones don't query again.
     */
    public function assertSettled(): void {
        if ($this->settled) {
            return;
        }

        $startedAt = $this->read()['startedAt'];
        if ($startedAt !== null) {
            throw new MigrationUnfinished($startedAt);
        }

        $this->settled = true;
    }
}
