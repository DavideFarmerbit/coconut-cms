<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Platform\Platforms;

/**
 * The DDL around a migration's move: expand before it, contract after. Each
 * compares the database as it is with the target, so running either again
 * after an interruption only does what's left. Never moves data: DDL can't be
 * rolled back, so it only ever adds, moves aside, or drops what's unused.
 * Every value column is nullable, so a row the move rewrites in the new shape
 * can leave a column on its way out empty.
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

            $this->moveColumnsAside($existing, $table);
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

    /**
     * Renames each column of $existing whose type $target changes out of the
     * way, once nothing but data hangs on it: its foreign keys and indexes
     * are dropped with the rename, in one table diff. Doctrine writes it as
     * each database needs: an ALTER on MySQL, a rebuilt table on SQLite.
     */
    private function moveColumnsAside(Table $existing, Table $target): void {
        $renamed = [];
        foreach ($this->comparator()->compareTables($existing, $target)->getChangedColumns() as $change) {
            $old = $change->getOldColumn();
            $renamed[$old->getName()] = new ColumnDiff($old, $old->edit()->setUnquotedName(ColumnNames::old($old->getName()))->create());
        }

        if ($renamed === []) {
            return;
        }

        $moved = array_keys($renamed);
        $diff = new TableDiff(
            $existing,
            changedColumns: $renamed,
            droppedIndexes: array_values(array_filter($existing->getIndexes(), static fn (Index $index): bool => !$index->isPrimary() && array_intersect($moved, $index->getColumns()) !== [])),
            droppedForeignKeys: array_values(array_filter($existing->getForeignKeys(), static fn (ForeignKeyConstraint $foreignKey): bool => array_intersect($moved, $foreignKey->getLocalColumns()) !== [])),
        );

        $this->execute($this->connection->getDatabasePlatform()->getAlterTableSQL($diff));
    }

    /**
     * Renames $table out of the way. Its own foreign keys, and every other
     * table's pointing at it, go first: a foreign key's name is unique across
     * a MySQL database, and one pointing at it would follow the rename.
     */
    private function moveTableAside(Table $table, Schema $current): void {
        $platform = $this->connection->getDatabasePlatform();
        $sql = [];

        foreach ($current->getTables() as $other) {
            $dropped = array_values(array_filter(
                $other->getForeignKeys(),
                static fn (ForeignKeyConstraint $foreignKey): bool => $other->getName() === $table->getName() || $foreignKey->getForeignTableName() === $table->getName(),
            ));
            if ($dropped !== []) {
                array_push($sql, ...$platform->getAlterTableSQL(new TableDiff($other, droppedForeignKeys: $dropped)));
            }
        }

        $sql[] = $platform->getRenameTableSQL($table->getName(), ColumnNames::old($table->getName()));
        $this->execute($sql);
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

    /** @param list<string> $sql Run the way the database needs schema changes run. */
    private function execute(array $sql): void {
        if ($sql === []) {
            return;
        }

        Platforms::of($this->connection)->changingSchema($this->connection, function () use ($sql): void {
            foreach ($sql as $statement) {
                $this->connection->executeStatement($statement);
            }
        });
    }
}
