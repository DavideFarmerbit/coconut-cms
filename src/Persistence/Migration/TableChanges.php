<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;

/**
 * The DDL around a migration's move: expand before it, contract after. Each
 * compares the database as it is with the target, so running either again
 * after an interruption only does what's left. Never moves data: DDL can't be
 * rolled back, so it only ever adds, moves aside, or drops what's unused.
 */
final readonly class TableChanges
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Gets the database ready for $target alongside what it holds now. A
     * column whose type changes is moved aside to "_old__<column>", and a
     * table whose kind changes to "_old__<table>", their indexes and foreign
     * keys dropped: they're no data. Then every table, column, index and
     * foreign key $target has and the database doesn't is added. Nothing is
     * dropped.
     * @return list<string> The tables moved aside, by their name in $target:
     *         where the move reads their old rows.
     */
    public function expand(Schema $target): array {
        $current = $this->current();
        $movedAside = [];

        foreach ($target->getTables() as $table) {
            if (!$current->hasTable($table->getName())) {
                continue;
            }

            $existing = $current->getTable($table->getName());
            if (self::keyOf($existing) !== self::keyOf($table)) {
                $this->moveTableAside($existing, $current);
                $movedAside[] = $table->getName();
                continue;
            }

            foreach ($this->comparator()->compareTables($existing, $table)->getChangedColumns() as $change) {
                $this->moveColumnAside($existing, $change->getOldColumn()->getName());
            }
        }

        // Moved aside by an earlier run that was cut short.
        foreach ($current->getTables() as $table) {
            $name = substr($table->getName(), strlen(ColumnNames::OLD));
            if (str_starts_with($table->getName(), ColumnNames::OLD) && $target->hasTable($name) && !in_array($name, $movedAside, true)) {
                $movedAside[] = $name;
            }
        }

        $this->execute($this->additionsSql($this->current(), $target));

        return $movedAside;
    }

    /**
     * Drops whatever $target no longer has from the content tables: every
     * "_old__" column and table, every column, index and foreign key the new
     * layout dropped, every table of a type that's gone. Anything outside
     * $contentTables, like the schema stores, is left alone.
     * @param list<string> $contentTables Every table the stored layout had.
     */
    public function contract(Schema $target, array $contentTables): void {
        $current = $this->current();

        $kept = [];
        foreach ($current->getTables() as $table) {
            $isContent = in_array($table->getName(), $contentTables, true) || str_starts_with($table->getName(), ColumnNames::OLD);
            if (!$isContent && !$target->hasTable($table->getName())) {
                $kept[] = $table;
            }
        }

        $diff = $this->comparator()->compareSchemas($current, new Schema([...$target->getTables(), ...$kept]));
        $this->execute($this->connection->getDatabasePlatform()->getAlterSchemaSQL($diff));
    }

    /**
     * Creates every table $target has and $current doesn't, then adds every
     * missing column, index and foreign key to the tables both have: never a
     * drop, never a change.
     * @return list<string>
     */
    private function additionsSql(Schema $current, Schema $target): array {
        $platform = $this->connection->getDatabasePlatform();
        $diff = $this->comparator()->compareSchemas($current, $target);

        $sql = $platform->getCreateTablesSQL($diff->getCreatedTables());
        foreach ($diff->getAlteredTables() as $tableDiff) {
            $additions = new TableDiff(
                $tableDiff->getOldTable(),
                addedColumns: $tableDiff->getAddedColumns(),
                addedIndexes: $tableDiff->getAddedIndexes(),
                addedForeignKeys: $tableDiff->getAddedForeignKeys(),
            );
            if (!$additions->isEmpty()) {
                array_push($sql, ...$platform->getAlterTableSQL($additions));
            }
        }

        return $sql;
    }

    /** Renames $column out of the way, once nothing but data hangs on it. */
    private function moveColumnAside(Table $table, string $column): void {
        $platform = $this->connection->getDatabasePlatform();
        $sql = [];

        foreach ($table->getForeignKeys() as $foreignKey) {
            if (in_array($column, $foreignKey->getLocalColumns(), true)) {
                $sql[] = $platform->getDropForeignKeySQL($foreignKey->getName(), $table->getName());
            }
        }

        foreach ($table->getIndexes() as $index) {
            if (!$index->isPrimary() && in_array($column, $index->getColumns(), true)) {
                $sql[] = $platform->getDropIndexSQL($index->getName(), $table->getName());
            }
        }

        $sql[] = sprintf(
            'ALTER TABLE %s RENAME COLUMN %s TO %s',
            $platform->quoteSingleIdentifier($table->getName()),
            $platform->quoteSingleIdentifier($column),
            $platform->quoteSingleIdentifier(ColumnNames::old($column)),
        );

        $this->execute($sql);
    }

    /**
     * Renames $table out of the way. Its own foreign keys, and every other
     * table's pointing at it, go first: a foreign key's name is unique across
     * the database, and one pointing at it would follow the rename.
     */
    private function moveTableAside(Table $table, Schema $current): void {
        $platform = $this->connection->getDatabasePlatform();
        $sql = [];

        foreach ($current->getTables() as $other) {
            foreach ($other->getForeignKeys() as $foreignKey) {
                if ($other->getName() === $table->getName() || $foreignKey->getForeignTableName() === $table->getName()) {
                    $sql[] = $platform->getDropForeignKeySQL($foreignKey->getName(), $other->getName());
                }
            }
        }

        $sql[] = $platform->getRenameTableSQL($table->getName(), ColumnNames::old($table->getName()));
        $this->execute(array_values(array_unique($sql)));
    }

    /** @return list<string> $table's primary key columns: what tells a level, a side and a Collection table apart. */
    private static function keyOf(Table $table): array {
        return array_map(
            static fn (UnqualifiedName $column): string => $column->toString(),
            $table->getPrimaryKeyConstraint()?->getColumnNames() ?? [],
        );
    }

    private function current(): Schema {
        return $this->connection->createSchemaManager()->introspectSchema();
    }

    /** Renames are never guessed: a migration declares every one, and moves its data itself. */
    private function comparator(): Comparator {
        return $this->connection->createSchemaManager()->createComparator(new ComparatorConfig(
            detectRenamedColumns: false,
            detectRenamedIndexes: false,
            reportModifiedIndexes: false,
        ));
    }

    /** @param list<string> $sql */
    private function execute(array $sql): void {
        foreach ($sql as $statement) {
            $this->connection->executeStatement($statement);
        }
    }
}
