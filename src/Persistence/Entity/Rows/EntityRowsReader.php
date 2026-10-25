<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Rows;

use Closure;
use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\CollectionLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelChains;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\OwnedLayout;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValuesBuilder;

/**
 * Reads an entity's rows back into its values, the reverse of
 * EntityRowsBuilder: every value, each in its stored form, and which of them
 * are flagged touched. An owned object's values are read from its own rows,
 * under its path.
 */
final class EntityRowsReader
{
    private readonly LevelChains $chains;

    /**
     * @param bool $readsMovedAside For a migration's move: a column a migration
     *        moved aside, "_old__weight", is read as the one it was, "weight".
     * @param list<string> $tablesMovedAside Tables a migration moved aside, by
     *        their name in $schemas: each is read from its "_old__" name.
     */
    public function __construct(
        private readonly Connection $connection,
        SchemaLookup $schemas,
        private readonly bool $readsMovedAside = false,
        private readonly array $tablesMovedAside = [],
    ) {
        $this->chains = new LevelChains($schemas);
    }

    /** Null when no entity has $id. */
    public function read(string $id): ?StoredEntity {
        $identifier = $this->connection->fetchOne(sprintf('SELECT concrete_identifier FROM %s WHERE id = ?', $this->quoted(DatabaseSchema::ENTITIES)), [$id]);
        if ($identifier === false) {
            return null;
        }

        $values = new FieldValuesBuilder();
        $touched = new FieldValuesBuilder();
        $ownedIds = [];
        $this->readInto($id, $identifier, null, $values, $touched, $ownedIds);

        return new StoredEntity($identifier, $values->build(), $touched->build(), $ownedIds);
    }

    /**
     * Adds what entity $id holds to $values, and what of it is flagged to
     * $touched, under $prefix.
     * @param FieldPath|null $prefix Where the entity sits, as a path from the
     *        one read() was called for; null for that one itself.
     * @param array<string, string> $ownedIds
     */
    private function readInto(string $id, string $identifier, ?FieldPath $prefix, FieldValuesBuilder $values, FieldValuesBuilder $touched, array &$ownedIds): void {
        $at = static fn (FieldPath $path): FieldPath => $prefix === null ? $path : $prefix->append(FieldPath::TRAVERSE, $path);

        foreach ($this->chains->of($identifier) as $level) {
            $row = $this->restored($this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE %s = ?', $this->tableOf($level->table), $this->quoted(ColumnNames::ID)), [$id]));
            $state = $this->restored($this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE %s = ?', $this->tableOf($level->stateTable), $this->quoted(ColumnNames::ID)), [$id]));
            $flags = $level->stateColumns();
            $isTouched = static fn (FieldPath $path): bool => (bool) $state[$flags[(string) $path]];
            // Sets a value, and marks it touched when its path is flagged.
            $set = static function (FieldPath $path, mixed $value, bool $isTouched) use ($at, $values, $touched): void {
                $values->set($at($path), $value);
                if ($isTouched) {
                    $touched->set($at($path), $value);
                }
            };

            foreach ($level->columns as $column) {
                $set($column->path, self::fromDatabase($column->field, $row[$column->name]), $isTouched($column->path));
            }

            foreach ($level->collections as $collection) {
                $this->readCollection($collection, $id, $isTouched($collection->path), $set);
            }

            foreach ($level->owned as $owned) {
                $this->readOwned($owned, $row, $id, $isTouched($owned->path), $at, $values, $touched, $ownedIds);
            }
        }
    }

    /**
     * Reads every item. A Scalar, ValueObject or Reference item is touched
     * with its Collection; an Embed item's field by its own flag.
     * @param Closure(FieldPath, mixed, bool): void $set Sets a value at a path on the owner.
     */
    private function readCollection(CollectionLayout $collection, string $ownerId, bool $isTouched, Closure $set): void {
        $rows = array_map($this->restored(...), $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ? ORDER BY %s', $this->tableOf($collection->table), $this->quoted(ColumnNames::OWNER), $this->quoted(ColumnNames::POSITION)),
            [$ownerId],
        ));
        $states = [];
        if ($collection->stateTable !== null) {
            foreach ($this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE %s = ?', $this->tableOf($collection->stateTable), $this->quoted(ColumnNames::OWNER)), [$ownerId]) as $state) {
                $state = $this->restored($state);
                $states[$state[ColumnNames::POSITION]] = $state;
            }
        }

        $set($collection->path, count($rows), $isTouched);
        foreach ($rows as $row) {
            $position = $row[ColumnNames::POSITION];
            $itemPath = $collection->path->traverse((int) $position);

            foreach ($collection->itemColumns as $column) {
                if ($column->path === null) {
                    $set($itemPath, self::fromDatabase($column->field, $row[$column->name]), $isTouched);
                } else {
                    $set($itemPath->append(FieldPath::TRAVERSE, $column->path), self::fromDatabase($column->field, $row[$column->name]), (bool) $states[$position][$column->name]);
                }
            }
        }
    }

    /**
     * The identifier each owned object holds, or null, then everything it holds of
     * its own, from its own rows. The slot, or the Owned Collection's count, is
     * touched by its owner's flag.
     * @param array<string, mixed> $ownerRow The owner's row at the level declaring $owned.
     * @param Closure(FieldPath): FieldPath $at
     * @param array<string, string> $ownedIds
     */
    private function readOwned(OwnedLayout $owned, array $ownerRow, string $ownerId, bool $isTouched, Closure $at, FieldValuesBuilder $values, FieldValuesBuilder $touched, array &$ownedIds): void {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT id, concrete_identifier, position FROM %s WHERE owner = ? AND owner_field = ?', $this->quoted(DatabaseSchema::ENTITIES)),
            [$ownerId, $owned->ownerField()],
        );
        $byPosition = array_column($rows, null, 'position');

        if ($owned->countColumn === null) {
            $this->readOwnedObject($owned->path, $byPosition[-1] ?? null, $isTouched, $at, $values, $touched, $ownedIds);

            return;
        }

        $count = (int) $ownerRow[$owned->countColumn];
        $values->set($at($owned->path), $count);
        if ($isTouched) {
            $touched->set($at($owned->path), $count);
        }

        for ($index = 0; $index < $count; $index++) {
            $this->readOwnedObject($owned->path->traverse($index), $byPosition[$index] ?? null, $isTouched, $at, $values, $touched, $ownedIds);
        }
    }

    /**
     * @param array<string, mixed>|null $row Its entities row; null for an empty slot.
     * @param bool $isTouched Whether its slot is: its identifier is then touched too.
     * @param Closure(FieldPath): FieldPath $at
     * @param array<string, string> $ownedIds
     */
    private function readOwnedObject(FieldPath $path, ?array $row, bool $isTouched, Closure $at, FieldValuesBuilder $values, FieldValuesBuilder $touched, array &$ownedIds): void {
        $values->set($at($path), $row['concrete_identifier'] ?? null);
        if ($isTouched) {
            $touched->set($at($path), $row['concrete_identifier'] ?? null);
        }

        if ($row === null) {
            return;
        }

        $ownedIds[(string) $at($path)] = $row['id'];
        $this->readInto($row['id'], $row['concrete_identifier'], $at($path), $values, $touched, $ownedIds);
    }

    /** Where $table's rows are read from, quoted: its "_old__" name when a migration moved it aside. */
    private function tableOf(string $table): string {
        return $this->quoted(in_array($table, $this->tablesMovedAside, true) ? ColumnNames::old($table) : $table);
    }

    /** $name quoted for this database: a project's table or field may be named like a keyword. */
    private function quoted(string $name): string {
        return $this->connection->quoteSingleIdentifier($name);
    }

    /**
     * $row with every column a migration moved aside read as the one it was:
     * "_old__weight" holds what "weight" did. Unchanged outside a migration.
     * @param array<string, mixed>|false $row
     * @return array<string, mixed>|false
     */
    private function restored(array|false $row): array|false {
        if (!$this->readsMovedAside || $row === false) {
            return $row;
        }

        foreach ($row as $column => $value) {
            if (str_starts_with((string) $column, ColumnNames::OLD)) {
                $row[substr((string) $column, strlen(ColumnNames::OLD))] = $value;
            }
        }

        return $row;
    }

    /**
     * $value as the database gave it back, in its stored form again: a
     * boolean comes back as 0 or 1, a number may come back as a string, and
     * SQLite gives a Decimal back as a number, which is written canonically
     * again.
     */
    private static function fromDatabase(FieldDescriptor $field, mixed $value): mixed {
        if ($value === null || $field->kind() !== FieldKind::Scalar) {
            return $value;
        }

        return match ($field->scalarType()) {
            ScalarType::Bool => (bool) $value,
            ScalarType::Int => (int) $value,
            ScalarType::Float => (float) $value,
            ScalarType::Enum => $field->enumBacking() === ScalarType::Int ? (int) $value : (string) $value,
            ScalarType::Decimal => ScalarValues::toStorage(ScalarType::Decimal, is_string($value) ? $value : number_format((float) $value, ScalarValues::DECIMAL_SCALE, '.', '')),
            default => $value,
        };
    }
}
