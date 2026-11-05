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
 * code it was last migrated to, whether a migration is reshaping the tables
 * right now, and the schema revision. One query answers "is a migration
 * pending?", "is one unfinished?" and "did the types change since?".
 *
 * The schema revision counts the migrations that changed stored schemas,
 * native or editor. An editor save is built on one revision, and is refused
 * once another migration raised it.
 *
 * A migration marks itself started before its first DDL and finished after
 * its last. While it's started, the tables and the stored schemas don't
 * match, so reads and writes refuse to run. It marks itself committed inside
 * the transaction storing the new schemas, so the row tells what a stopped
 * one left: before the commit the stored schemas are the old ones, after it
 * the new ones. One that crashed stays started, until the next migration
 * cleans up after it.
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

    /** Creates the table, unless it exists; an older one gains the columns it lacks. */
    public function ensureTable(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            $existing = $schemaManager->introspectTable(self::TABLE);
            foreach (['schema_revision' => 'INTEGER', 'migration_committed' => 'SMALLINT'] as $column => $type) {
                if (!$existing->hasColumn($column)) {
                    $this->connection->executeStatement(sprintf(
                        'ALTER TABLE %s ADD COLUMN %s %s NOT NULL DEFAULT 0',
                        $this->connection->quoteSingleIdentifier(self::TABLE),
                        $column,
                        $type,
                    ));
                }
            }

            return;
        }

        $table = new Table(self::TABLE);
        $table->addColumn('id', Types::SMALLINT);
        $table->addColumn('state_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('migration_started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('migration_committed', Types::SMALLINT, ['default' => 0]);
        $table->addColumn('schema_revision', Types::INTEGER, ['default' => 0]);
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
            sprintf('UPDATE %s SET migration_started_at = COALESCE(migration_started_at, ?), migration_committed = 0 WHERE id = ?', $table),
            [$now, self::ROW],
            [Types::DATETIME_IMMUTABLE, Types::SMALLINT],
        );
        if ($this->exists($updated)) {
            return;
        }

        $this->connection->insert($table, ['id' => self::ROW, 'migration_started_at' => $now], ['id' => Types::SMALLINT, 'migration_started_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Marks the started migration's move committed. Called inside the
     * transaction storing the new schemas, so it commits with them or not at
     * all.
     */
    public function commit(): void {
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET migration_committed = 1 WHERE id = ?', $this->connection->quoteSingleIdentifier(self::TABLE)),
            [self::ROW],
            [Types::SMALLINT],
        );
    }

    /**
     * Ends a stopped migration whose tables were cleaned up: the state key
     * stays what it was. Raises the schema revision when $schemasChanged: one
     * that stopped past its commit did change them.
     */
    public function end(bool $schemasChanged): void {
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET migration_started_at = NULL, migration_committed = 0, schema_revision = schema_revision + ? WHERE id = ?', $this->connection->quoteSingleIdentifier(self::TABLE)),
            [$schemasChanged ? 1 : 0, self::ROW],
            [Types::INTEGER, Types::SMALLINT],
        );
    }

    /**
     * Marks the migration finished, the database now matching the code whose
     * state key is $stateKey. Raises the schema revision when $schemasChanged:
     * one that only moved nothing leaves it. Holds the migration lock too.
     */
    public function finish(string $stateKey, bool $schemasChanged): void {
        $table = $this->connection->quoteSingleIdentifier(self::TABLE);
        $raise = $schemasChanged ? 1 : 0;

        $updated = $this->connection->executeStatement(
            sprintf('UPDATE %s SET state_key = ?, migration_started_at = NULL, migration_committed = 0, schema_revision = schema_revision + ? WHERE id = ?', $table),
            [$stateKey, $raise, self::ROW],
            [Types::STRING, Types::INTEGER, Types::SMALLINT],
        );
        if ($this->exists($updated)) {
            return;
        }

        $this->connection->insert(
            $table,
            ['id' => self::ROW, 'state_key' => $stateKey, 'schema_revision' => $raise],
            ['id' => Types::SMALLINT, 'state_key' => Types::STRING, 'schema_revision' => Types::INTEGER],
        );
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
     * running now started, both null when there's none; whether that
     * migration's move committed; and the schema revision. A database no
     * migration ever ran on has neither, and revision 0.
     * @return array{stateKey: ?string, startedAt: ?string, committed: bool, revision: int}
     */
    public function read(): array {
        try {
            // Every column: one made before the schema revision has none yet.
            $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', $this->connection->quoteSingleIdentifier(self::TABLE)), [self::ROW]);
        } catch (TableNotFoundException) {
            $row = false;
        }

        return [
            'stateKey' => $row['state_key'] ?? null,
            'startedAt' => $row['migration_started_at'] ?? null,
            'committed' => (bool) ($row['migration_committed'] ?? false),
            'revision' => (int) ($row['schema_revision'] ?? 0),
        ];
    }

    /** The schema revision: how many migrations changed stored schemas. */
    public function revision(): int {
        return $this->read()['revision'];
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
