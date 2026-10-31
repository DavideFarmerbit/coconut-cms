<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Uniqueness;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRows;

/**
 * Finds the values a migration would make a unique index hold twice, before
 * anything changes: a unique group added over content that breaks it, or a
 * converter giving two entries the same value. Each index new to the tables,
 * or on a table the migration writes, is checked against the rows it would
 * hold: the moved entries' rows, as the migration would write them, and
 * every other row as it is.
 *
 * The database compares, never PHP: its collation decides what's equal, and
 * MySQL's ignores case and accents. Each check fills a temporary table typed
 * as the index's columns, and groups it.
 */
final class UniquenessCheck
{
    /** The temporary table each check fills, then drops. */
    private const KEYS = 'cc_unique_check';

    /** @var array<string, list<array<string, mixed>>> The moved rows' keys, by index, each its entity and columns. */
    private array $moved = [];

    /** @var array<string, true> Every table a moved row lands in. */
    private array $written = [];

    /**
     * @param list<UniqueIndex> $indexes Every unique index the migration's tables will have.
     * @param Schema $target The tables the migration lays out.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $indexes,
        private readonly Schema $target,
    ) {
    }

    /**
     * Takes the rows a moved aggregate would be written as, at each index on
     * their tables.
     * @param list<EntityRows> $entities
     */
    public function addMoved(array $entities): void {
        foreach ($entities as $entity) {
            foreach ($entity->contentRows() as $row) {
                $this->written[$row->table] = true;
                foreach ($this->indexes as $key => $index) {
                    if ($index->table === $row->table) {
                        $this->moved[$key][] = [$index->entityColumn => $row->values[$index->entityColumn], ...array_intersect_key($row->values, array_flip($index->columns))];
                    }
                }
            }
        }
    }

    /**
     * Every value an index would hold twice. Rows of the entities in
     * $replaced are left out of what's stored: the migration rewrites them.
     * @param list<string> $replaced The id of every entity the migration moves or deletes.
     * @return list<Duplicate>
     */
    public function duplicates(array $replaced): array {
        $duplicates = [];
        foreach ($this->indexes as $key => $index) {
            if ($index->new || isset($this->written[$index->table])) {
                array_push($duplicates, ...$this->duplicatesOf($index, $this->moved[$key] ?? [], $replaced));
            }
        }

        return $duplicates;
    }

    /**
     * @param list<array<string, mixed>> $moved
     * @param list<string> $replaced
     * @return list<Duplicate>
     */
    private function duplicatesOf(UniqueIndex $index, array $moved, array $replaced): array {
        $this->createKeys($index);
        try {
            foreach ($moved as $row) {
                // PDO would send false as an empty string, which no boolean column takes.
                $this->connection->insert(self::KEYS, $row, array_map(static fn (mixed $value): ParameterType => is_bool($value) ? ParameterType::BOOLEAN : ParameterType::STRING, $row));
            }

            $this->copyStored($index, $replaced);

            return $this->groupKeys($index);
        } finally {
            $this->connection->executeStatement($this->connection->getDatabasePlatform()->getDropTemporaryTableSQL(self::KEYS));
        }
    }

    /** Creates the temporary table, typed and collated as the index's table. */
    private function createKeys(UniqueIndex $index): void {
        $table = $this->target->getTable($index->table);
        $columns = [];
        foreach ([$index->entityColumn, ...$index->columns] as $name) {
            if (!isset($columns[$name])) {
                $columns[$name] = (clone $table->getColumn($name))->setNotnull(false);
            }
        }

        $platform = $this->connection->getDatabasePlatform();
        foreach ($platform->getCreateTableSQL(new Table(self::KEYS, array_values($columns), options: $table->getOptions())) as $sql) {
            $this->connection->executeStatement((string) preg_replace('/^CREATE TABLE/', $platform->getCreateTemporaryTableSnippetSQL(), $sql));
        }
    }

    /**
     * Copies the index's columns of every stored row the migration leaves as
     * it is, when the table already holds them all.
     * @param list<string> $replaced
     */
    private function copyStored(UniqueIndex $index, array $replaced): void {
        $manager = $this->connection->createSchemaManager();
        if (!$manager->tablesExist([$index->table])) {
            return;
        }

        $stored = $manager->introspectTable($index->table);
        $columns = array_unique([$index->entityColumn, ...$index->columns]);
        foreach ($columns as $column) {
            if (!$stored->hasColumn($column)) {
                return;
            }
        }

        $quoted = implode(', ', array_map($this->connection->quoteSingleIdentifier(...), $columns));
        $sql = sprintf('INSERT INTO %s (%s) SELECT %s FROM %s', self::KEYS, $quoted, $quoted, $this->connection->quoteSingleIdentifier($index->table));
        if ($replaced === []) {
            $this->connection->executeStatement($sql);

            return;
        }

        $this->connection->executeStatement(
            $sql . sprintf(' WHERE %s NOT IN (?)', $this->connection->quoteSingleIdentifier($index->entityColumn)),
            [$replaced],
            [ArrayParameterType::STRING],
        );
    }

    /**
     * Groups the keys: a unique index ignores a row holding null in any of
     * its columns.
     * @return list<Duplicate>
     */
    private function groupKeys(UniqueIndex $index): array {
        $columns = array_map($this->connection->quoteSingleIdentifier(...), $index->columns);
        $notNull = implode(' AND ', array_map(static fn (string $column): string => $column . ' IS NOT NULL', $columns));
        $groups = $this->connection->fetchAllAssociative(sprintf(
            'SELECT %1$s FROM %2$s WHERE %3$s GROUP BY %1$s HAVING COUNT(*) > 1',
            implode(', ', $columns),
            self::KEYS,
            $notNull,
        ));

        $duplicates = [];
        foreach ($groups as $group) {
            $where = implode(' AND ', array_map(static fn (string $column): string => $column . ' = ?', $columns));
            $entities = $this->connection->fetchFirstColumn(
                sprintf('SELECT %s FROM %s WHERE %s', $this->connection->quoteSingleIdentifier($index->entityColumn), self::KEYS, $where),
                array_values($group),
            );
            $duplicates[] = new Duplicate($index->type, array_intersect_key($group, array_flip($index->valueColumns)), array_values(array_unique(array_map('strval', $entities))));
        }

        return $duplicates;
    }
}
