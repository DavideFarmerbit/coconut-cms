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
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValuesBuilder;

/**
 * Reads an entity's rows back into its touched values, the reverse of
 * EntityRowsBuilder: only flagged values count, each in its stored form, and
 * an owned object's values are read from its own rows, under its path.
 */
final class EntityRowsReader
{
    private readonly LevelChains $chains;

    public function __construct(
        private readonly Connection $connection,
        SchemaLookup $schemas,
    ) {
        $this->chains = new LevelChains($schemas);
    }

    /** Null when no entity has $id. */
    public function read(string $id): ?StoredEntity {
        $identifier = $this->connection->fetchOne(sprintf('SELECT concrete_identifier FROM %s WHERE id = ?', DatabaseSchema::ENTITIES), [$id]);
        if ($identifier === false) {
            return null;
        }

        $touched = new FieldValuesBuilder();
        $ownedIds = [];
        $this->readInto($id, $identifier, null, $touched, $ownedIds);

        return new StoredEntity($identifier, $touched->build(), $ownedIds);
    }

    /**
     * Adds what entity $id holds to $touched, under $prefix.
     * @param FieldPath|null $prefix Where the entity sits, as a path from the
     *        one read() was called for; null for that one itself.
     * @param array<string, string> $ownedIds
     */
    private function readInto(string $id, string $identifier, ?FieldPath $prefix, FieldValuesBuilder $touched, array &$ownedIds): void {
        $at = static fn (FieldPath $path): FieldPath => $prefix === null ? $path : $prefix->append(FieldPath::TRAVERSE, $path);

        foreach ($this->chains->of($identifier) as $level) {
            $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE %s = ?', $level->table, ColumnNames::ID), [$id]);
            $state = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE %s = ?', $level->stateTable, ColumnNames::ID), [$id]);
            $flags = $level->stateColumns();
            $isTouched = static fn (FieldPath $path): bool => (bool) $state[$flags[(string) $path]];

            foreach ($level->columns as $column) {
                if ($isTouched($column->path)) {
                    $touched->set($at($column->path), self::fromDatabase($column->field, $row[$column->name]));
                }
            }

            foreach ($level->collections as $collection) {
                if ($isTouched($collection->path)) {
                    $this->readCollection($collection, $id, $at, $touched);
                }
            }

            foreach ($level->owned as $owned) {
                if ($isTouched($owned->path)) {
                    $this->readOwned($owned, $row, $id, $at, $touched, $ownedIds);
                }
            }
        }
    }

    /** @param Closure(FieldPath): FieldPath $at Turns a path on the owner into one from the entity being read. */
    private function readCollection(CollectionLayout $collection, string $ownerId, Closure $at, FieldValuesBuilder $touched): void {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ? ORDER BY %s', $collection->table, ColumnNames::OWNER, ColumnNames::POSITION),
            [$ownerId],
        );
        $states = [];
        if ($collection->stateTable !== null) {
            foreach ($this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE %s = ?', $collection->stateTable, ColumnNames::OWNER), [$ownerId]) as $state) {
                $states[$state[ColumnNames::POSITION]] = $state;
            }
        }

        $touched->set($at($collection->path), count($rows));
        foreach ($rows as $row) {
            $position = $row[ColumnNames::POSITION];
            $itemPath = $collection->path->traverse((int) $position);

            foreach ($collection->itemColumns as $column) {
                if ($column->path === null) {
                    $touched->set($at($itemPath), self::fromDatabase($column->field, $row[$column->name]));
                } elseif ($states[$position][$column->name]) {
                    $touched->set($at($itemPath->append(FieldPath::TRAVERSE, $column->path)), self::fromDatabase($column->field, $row[$column->name]));
                }
            }
        }
    }

    /**
     * The identifier each owned object holds, or null, then everything it holds of
     * its own, from its own rows.
     * @param array<string, mixed> $ownerRow The owner's row at the level declaring $owned.
     * @param Closure(FieldPath): FieldPath $at
     * @param array<string, string> $ownedIds
     */
    private function readOwned(OwnedLayout $owned, array $ownerRow, string $ownerId, Closure $at, FieldValuesBuilder $touched, array &$ownedIds): void {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT id, concrete_identifier, position FROM %s WHERE owner = ? AND owner_field = ?', DatabaseSchema::ENTITIES),
            [$ownerId, $owned->ownerField()],
        );
        $byPosition = array_column($rows, null, 'position');

        if ($owned->countColumn === null) {
            $this->readOwnedObject($owned->path, $byPosition[-1] ?? null, $at, $touched, $ownedIds);

            return;
        }

        $count = (int) $ownerRow[$owned->countColumn];
        $touched->set($at($owned->path), $count);
        for ($index = 0; $index < $count; $index++) {
            $this->readOwnedObject($owned->path->traverse($index), $byPosition[$index] ?? null, $at, $touched, $ownedIds);
        }
    }

    /**
     * @param array<string, mixed>|null $row Its entities row; null for an empty slot.
     * @param Closure(FieldPath): FieldPath $at
     * @param array<string, string> $ownedIds
     */
    private function readOwnedObject(FieldPath $path, ?array $row, Closure $at, FieldValuesBuilder $touched, array &$ownedIds): void {
        $touched->set($at($path), $row['concrete_identifier'] ?? null);
        if ($row === null) {
            return;
        }

        $ownedIds[(string) $at($path)] = $row['id'];
        $this->readInto($row['id'], $row['concrete_identifier'], $at($path), $touched, $ownedIds);
    }

    /**
     * $value as the database gave it back, in its stored form again: a
     * boolean comes back as 0 or 1, a number may come back as a string.
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
            default => $value,
        };
    }
}
