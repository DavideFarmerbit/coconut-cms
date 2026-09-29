<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use LogicException;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Attributes\Ownership;

/**
 * Translates a FieldDescriptor[] shape into Doctrine DBAL Tables: a real column for
 * every queryable field, one JSON blob column for everything else, and a separate
 * table for every reference/collection field that needs one.
 *
 * Walks embedded value objects recursively, a queryable field nested inside an
 * otherwise-blob embed still gets its own dot-flattened column (`address` + `city` ->
 * `address_city`); everything non-queryable at any depth stays in the blob instead.
 */
final class SchemaBuilder
{
    /** The column holding every non-queryable field, JSON-encoded. */
    public const string BLOB_COLUMN = 'data';

    /** The primary key column, an auto-incrementing integer, exposed to PHP as a string id. */
    public const string ID_COLUMN = 'id';

    /** The shared identity table every entity chain roots on (Phase 8), not derived from any class. */
    public const string ENTITIES_TABLE = 'entities';

    /**
     * The one shared entities table: id, an optional self-referencing owner (which
     * Owned relationship put this row here, and where in it), and the row's actual
     * concrete identifier. Fixed, hand-designed infrastructure, not derived from a
     * FieldDescriptor shape, the same posture PrototypeRegistry::schemaTables() takes
     * for its own meta-schema tables.
     */
    public static function entitiesTable(): Table
    {
        $table = new Table(self::ENTITIES_TABLE);
        $table->addColumn(self::ID_COLUMN, Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('owner', Types::INTEGER)->setNotnull(false);
        $table->addColumn('owner_field', Types::STRING)->setNotnull(false);
        $table->addColumn('position', Types::INTEGER)->setNotnull(false);
        $table->addColumn('concrete_type', Types::STRING);
        $table->setPrimaryKey([self::ID_COLUMN]);
        $table->addForeignKeyConstraint(self::ENTITIES_TABLE, ['owner'], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);
        $table->addIndex(['owner', 'owner_field']);

        return $table;
    }

    /**
     * A prototype with no reference or collection fields, so it needs exactly one table
     * of its own, on top of the implicit entities root every chain now has (Phase 8).
     *
     * @param FieldDescriptor[] $fields
     */
    public static function tableFor(string $tableName, array $fields): Table
    {
        $extra = [];

        return self::withEntitiesForeignKey(self::buildTable($tableName, $fields, [], $extra));
    }

    /**
     * @param FieldDescriptor[] $fields
     * @param array<class-string, string> $tables entity class => table name, to resolve what a reference/collection field points at
     * @return Table[] the main table first, then any join/child table a reference or collection field needed
     */
    public static function tablesFor(string $tableName, array $fields, array $tables): array
    {
        $extra = [];
        $table = self::withEntitiesForeignKey(self::buildTable($tableName, $fields, $tables, $extra));

        return [$table, ...$extra];
    }

    /**
     * Class Table Inheritance: one table per level, base first, on top of the implicit
     * entities root every chain now has (Phase 8), the same shared identity table for
     * every identifier, not one root per chain. Each level's own id column is also its
     * FK back to the previous level's row (or entities itself, for the first level),
     * CASCADE, since a derived row has no meaning without its base row.
     *
     * @param class-string[] $chain base first, PrototypeShape::chainOfClass() order
     * @param array<class-string, string> $tables entity class => table name, every level included
     * @param (callable(string): FieldDescriptor[])|null $ownFieldsOfLevel resolves one
     *   level's own fields, defaults to native reflection; PrototypeRegistry::ownFieldsOf()
     *   is the editor-created-aware equivalent, needed once a chain can include an
     *   editor-created level, which isn't a real class reflection can read
     * @return Table[] one per level, base first, then any join/child table a reference or collection field needed
     */
    public static function tablesForChain(array $chain, array $tables, ?callable $ownFieldsOfLevel = null): array
    {
        $ownFieldsOfLevel ??= PrototypeShape::ownFieldsOfClass(...);

        $result = [];
        $extra = [];
        $previousTable = null;

        foreach ($chain as $level) {
            $tableName = self::tableOf($level, $tables);
            $table = self::buildTable($tableName, $ownFieldsOfLevel($level), $tables, $extra);
            $table->addForeignKeyConstraint($previousTable ?? self::ENTITIES_TABLE, [self::ID_COLUMN], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);

            $result[] = $table;
            $previousTable = $tableName;
        }

        return [...$result, ...$extra];
    }

    private static function withEntitiesForeignKey(Table $table): Table
    {
        $table->addForeignKeyConstraint(self::ENTITIES_TABLE, [self::ID_COLUMN], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);

        return $table;
    }

    /**
     * @param FieldDescriptor[] $fields
     * @param array<class-string, string> $tables
     * @param Table[] $extra filled with any join/child table a reference or collection field needs
     */
    private static function buildTable(string $tableName, array $fields, array $tables, array &$extra): Table
    {
        $table = new Table($tableName);
        $table->addColumn(self::ID_COLUMN, Types::INTEGER);
        $table->setPrimaryKey([self::ID_COLUMN]);

        foreach ($fields as $field) {
            self::addColumns($table, $field, '', $tables, $extra);
        }

        $table->addColumn(self::BLOB_COLUMN, Types::TEXT);

        return $table;
    }

    /**
     * Every real column name $fields would produce, in the exact order buildTable()
     * physically creates them: 'id' isn't included, Repository/Query add it
     * separately, a Collection field never has one (its own join/child table for
     * Shared, entities.owner for Owned), a Shared EntityReference contributes
     * {name}_id regardless of queryable, an Owned one has no column at all (Phase 8
     * Step C: resolved through entities.owner/owner_field instead), an
     * EmbeddedValueObject recurses and flattens the same way addColumns() does. Needs
     * no $tables map, unlike addColumns(), a reference column's name never depends on
     * what its target resolves to.
     *
     * Used by Query to slice/alias a level's own row without touching the live
     * database or building a throwaway Table just to read its column names back.
     *
     * @param FieldDescriptor[] $fields
     * @return string[]
     */
    public static function realColumnNames(array $fields, string $prefix = ''): array
    {
        $names = [];
        foreach ($fields as $field) {
            if ($field->kind === FieldKind::EmbeddedValueObject) {
                array_push($names, ...self::realColumnNames(PrototypeShape::ofClass($field->referencedShape), $prefix . $field->name . '_'));

                continue;
            }

            if ($field->kind === FieldKind::EntityReference) {
                if ($field->ownership === Ownership::Shared) {
                    $names[] = $prefix . $field->name . '_id';
                }

                continue;
            }

            if ($field->kind === FieldKind::Collection || !$field->queryable) {
                continue;
            }

            $names[] = $prefix . $field->name;
        }

        return $names;
    }

    /**
     * @param array<class-string, string> $tables
     * @param Table[] $extra
     */
    private static function addColumns(Table $table, FieldDescriptor $field, string $prefix, array $tables, array &$extra): void
    {
        if ($field->kind === FieldKind::EmbeddedValueObject) {
            foreach (PrototypeShape::ofClass($field->referencedShape) as $nested) {
                self::addColumns($table, $nested, $prefix . $field->name . '_', $tables, $extra);
            }

            return;
        }

        if ($field->kind === FieldKind::EntityReference) {
            // Owned gets no column at all: resolved through entities.owner/owner_field instead (Phase 8 Step C).
            if ($field->ownership === Ownership::Shared) {
                self::addReferenceColumn($table, $field, $prefix, $tables);
            }

            return;
        }

        if ($field->kind === FieldKind::Collection) {
            array_push($extra, ...self::collectionTables($table->getName(), $field, $tables));

            return;
        }

        if (!$field->queryable) {
            return;
        }

        $columnName = $prefix . $field->name;
        $table->addColumn($columnName, self::columnType($field))->setNotnull(false);

        if ($field->unique) {
            $table->addUniqueIndex([$columnName]);
        }
    }

    /** @param array<class-string, string> $tables */
    private static function addReferenceColumn(Table $table, FieldDescriptor $field, string $prefix, array $tables): void
    {
        $columnName = $prefix . $field->name . '_id';
        $targetTable = self::tableOf($field->referencedShape, $tables);

        $table->addColumn($columnName, Types::INTEGER)->setNotnull(false);
        $table->addForeignKeyConstraint($targetTable, [$columnName], [self::ID_COLUMN], [
            'onDelete' => $field->ownership === Ownership::Owned ? 'CASCADE' : 'RESTRICT',
        ]);

        if ($field->unique) {
            $table->addUniqueIndex([$columnName]);
        }
    }

    /**
     * Shared: a many-to-many join table, its own row disappears with either side, the
     * two entities themselves keep their own independent lifecycle either way.
     *
     * Owned: no table of its own at all (Phase 8 Step C). Each item is a real,
     * independently-tabled entity via its own chain, found through
     * entities.owner/owner_field/position instead of a dedicated per-relationship
     * child table, see Repository::readCollection()/writeCollection().
     *
     * @param array<class-string, string> $tables
     * @return Table[]
     */
    private static function collectionTables(string $ownerTable, FieldDescriptor $field, array $tables): array
    {
        if ($field->collectionItemKind !== FieldKind::EntityReference) {
            throw new LogicException(sprintf(
                'Collection "%s" of %s items is not supported yet.',
                $field->name,
                $field->collectionItemKind?->name ?? 'unknown',
            ));
        }

        if ($field->ownership === Ownership::Owned) {
            return [];
        }

        $name = $ownerTable . '_' . $field->name;
        $itemTable = self::tableOf($field->referencedShape, $tables);

        $join = new Table($name);
        $join->addColumn('owner_id', Types::INTEGER);
        $join->addColumn('item_id', Types::INTEGER);
        $join->setPrimaryKey(['owner_id', 'item_id']);
        $join->addForeignKeyConstraint($ownerTable, ['owner_id'], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);
        $join->addForeignKeyConstraint($itemTable, ['item_id'], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);

        return [$join];
    }

    /** @param array<class-string, string> $tables */
    private static function tableOf(string $class, array $tables): string
    {
        return $tables[$class] ?? throw new LogicException(sprintf('No table registered for %s.', $class));
    }

    private static function columnType(FieldDescriptor $field): string
    {
        return match ($field->kind) {
            FieldKind::String => Types::STRING,
            FieldKind::Int => Types::INTEGER,
            FieldKind::Float => Types::FLOAT,
            FieldKind::Bool => Types::BOOLEAN,
            FieldKind::Choice => is_int($field->choiceOptions[0] ?? null) ? Types::INTEGER : Types::STRING,
            default => throw new LogicException(sprintf('FieldKind %s is not queryable yet.', $field->kind->name)),
        };
    }
}
