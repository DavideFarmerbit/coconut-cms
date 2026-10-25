<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Rows;

use Closure;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\CollectionLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelChains;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\OwnedLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ValueColumn;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * Works out every row an entity's values should be stored as, and every row
 * of what it owns: plain data, never touching the database.
 *
 * Every value lands in its column, touched or not: content is dense. Its flag
 * says which: set when touched, clear when it follows its default. Every
 * owned object and every Collection item gets its rows, the default ones
 * included. An owned slot or a Collection is flagged when anything at or
 * under it is touched.
 */
final class EntityRowsBuilder
{
    private readonly LevelChains $chains;

    public function __construct(SchemaLookup $schemas) {
        $this->chains = new LevelChains($schemas);
    }

    /**
     * @param Closure(FieldPath): string $ownedIdOf The id of the object owned
     *        at a path from this entity, given a new one if it
     *        never had one.
     * @return list<EntityRows> This entity's first, then everything it owns,
     *         each owner before what it owns.
     */
    public function rowsOf(EntityValues $values, string $id, Placement $placement, Closure $ownedIdOf): array {
        return $this->entityRows($values->identifier, $values->dense, $values->touched, $id, $placement, null, $ownedIdOf);
    }

    /**
     * @param ?FieldPath $prefix Where this entity sits, as a path from the
     *        entity rowsOf() was called for; null for that entity itself.
     * @return list<EntityRows>
     */
    private function entityRows(string $identifier, FieldValues $dense, FieldValues $touched, string $id, Placement $placement, ?FieldPath $prefix, Closure $ownedIdOf): array {
        $rows = [new Row(DatabaseSchema::ENTITIES, [
            'id' => $id,
            'owner' => $placement->owner,
            'owner_field' => $placement->ownerField,
            'position' => $placement->position,
            'concrete_identifier' => $identifier,
        ])];
        $owned = [];

        foreach ($this->chains->of($identifier) as $level) {
            $rows[] = new Row($level->table, $this->levelRowOf($level, $dense, $id, $placement), self::referenceColumnsOf($level->columns));
            $rows[] = new Row($level->stateTable, $this->stateRowOf($level, $touched, $id));

            foreach ($level->collections as $collection) {
                array_push($rows, ...$this->collectionRows($collection, $dense, $touched, $id));
            }

            foreach ($level->owned as $ownedLayout) {
                array_push($owned, ...$this->ownedRows($ownedLayout, $dense, $touched, $id, $prefix, $ownedIdOf));
            }
        }

        return [new EntityRows($id, $identifier, $rows), ...$owned];
    }

    /**
     * @param list<ValueColumn> $columns
     * @return list<string> The names of those holding a Reference's id.
     */
    private static function referenceColumnsOf(array $columns): array {
        return array_values(array_map(
            static fn (ValueColumn $column): string => $column->name,
            array_filter($columns, static fn (ValueColumn $column): bool => $column->field->kind() === FieldKind::Reference),
        ));
    }

    /**
     * Every value $level's columns hold, touched or not.
     * @return array<string, mixed>
     */
    private function levelRowOf(LevelLayout $level, FieldValues $dense, string $id, Placement $placement): array {
        $row = [ColumnNames::ID => $id, ColumnNames::OWNER => $placement->owner, ColumnNames::OWNER_FIELD => $placement->ownerField];

        foreach ($level->columns as $column) {
            $row[$column->name] = $dense->get($column->path);
        }

        foreach ($level->owned as $owned) {
            if ($owned->countColumn !== null) {
                $row[$owned->countColumn] = $dense->get($owned->path);
            }
        }

        return $row;
    }

    /**
     * A path counts as touched when anything at or under it is: so an owned
     * object with one field set is flagged, along with that field.
     * @return array<string, mixed>
     */
    private function stateRowOf(LevelLayout $level, FieldValues $touched, string $id): array {
        $row = [ColumnNames::ID => $id];
        foreach ($level->stateColumns() as $path => $column) {
            $row[$column] = $touched->under(FieldPath::fromString($path))->count() > 0;
        }

        return $row;
    }

    /**
     * One row per item, and one state row per item for Embed items, whether
     * the Collection is touched or follows its default.
     * @return list<Row>
     */
    private function collectionRows(CollectionLayout $collection, FieldValues $dense, FieldValues $touched, string $id): array {
        $rows = [];
        $stateRows = [];
        for ($index = 0; $index < $dense->get($collection->path); $index++) {
            $itemPath = $collection->path->traverse($index);
            $item = $dense->within($itemPath);
            $touchedItem = $touched->within($itemPath);
            $row = [ColumnNames::OWNER => $id, ColumnNames::POSITION => $index];
            $stateRow = [ColumnNames::OWNER => $id, ColumnNames::POSITION => $index];

            foreach ($collection->itemColumns as $column) {
                if ($column->path === null) {
                    // A Scalar, ValueObject or Reference item: the value itself.
                    $row[$column->name] = $dense->get($itemPath);
                    continue;
                }

                $row[$column->name] = $item->get($column->path);
                $stateRow[$column->name] = $touchedItem->has($column->path);
            }

            $rows[] = new Row($collection->table, $row, self::referenceColumnsOf($collection->itemColumns));
            if ($collection->stateTable !== null) {
                $stateRows[] = new Row($collection->stateTable, $stateRow);
            }
        }

        return [...$rows, ...$stateRows];
    }

    /**
     * The rows of what's owned at $owned's path, touched or not: the owned
     * object's own rows, or each item's for an Owned Collection; nothing for
     * an empty slot.
     * @return list<EntityRows>
     */
    private function ownedRows(OwnedLayout $owned, FieldValues $dense, FieldValues $touched, string $ownerId, ?FieldPath $prefix, Closure $ownedIdOf): array {
        if ($owned->countColumn === null) {
            return $this->ownedObjectRows($owned->path, $dense, $touched, Placement::owned($ownerId, $owned->path), $prefix, $ownedIdOf);
        }

        $rows = [];
        for ($index = 0; $index < $dense->get($owned->path); $index++) {
            $placement = Placement::owned($ownerId, $owned->path, $index);
            array_push($rows, ...$this->ownedObjectRows($owned->path->traverse($index), $dense, $touched, $placement, $prefix, $ownedIdOf));
        }

        return $rows;
    }

    /**
     * The rows of the object held at $path, nothing if it holds none.
     * @return list<EntityRows>
     */
    private function ownedObjectRows(FieldPath $path, FieldValues $dense, FieldValues $touched, Placement $placement, ?FieldPath $prefix, Closure $ownedIdOf): array {
        $identifier = $dense->get($path);
        if ($identifier === null) {
            return [];
        }

        $fullPath = $prefix === null ? $path : $prefix->append(FieldPath::TRAVERSE, $path);

        return $this->entityRows($identifier, $dense->within($path), $touched->within($path), $ownedIdOf($fullPath), $placement, $fullPath, $ownedIdOf);
    }
}
