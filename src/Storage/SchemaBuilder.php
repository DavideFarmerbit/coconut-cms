<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use LogicException;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Field\Ownership;

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

    /**
     * A prototype with no reference or collection fields, so it needs exactly one table.
     *
     * @param FieldDescriptor[] $fields
     */
    public static function tableFor(string $tableName, array $fields): Table
    {
        $extra = [];

        return self::buildTable($tableName, $fields, [], $extra);
    }

    /**
     * @param FieldDescriptor[] $fields
     * @param array<class-string, string> $tables entity class => table name, to resolve what a reference/collection field points at
     * @return Table[] the main table first, then any join/child table a reference or collection field needed
     */
    public static function tablesFor(string $tableName, array $fields, array $tables): array
    {
        $extra = [];
        $table = self::buildTable($tableName, $fields, $tables, $extra);

        return [$table, ...$extra];
    }

    /**
     * Class Table Inheritance: one table per level, base first. Each derived level's
     * own id column is also its FK back to the previous level's row, CASCADE, since a
     * derived row has no meaning without its base row.
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
            $table = self::buildTable($tableName, $ownFieldsOfLevel($level), $tables, $extra, $previousTable === null);

            if ($previousTable !== null) {
                $table->addForeignKeyConstraint($previousTable, [self::ID_COLUMN], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);
            }

            $result[] = $table;
            $previousTable = $tableName;
        }

        return [...$result, ...$extra];
    }

    /**
     * @param FieldDescriptor[] $fields
     * @param array<class-string, string> $tables
     * @param Table[] $extra filled with any join/child table a reference or collection field needs
     */
    private static function buildTable(string $tableName, array $fields, array $tables, array &$extra, bool $autoIncrementId = true): Table
    {
        $table = new Table($tableName);
        $table->addColumn(self::ID_COLUMN, Types::INTEGER, ['autoincrement' => $autoIncrementId]);
        $table->setPrimaryKey([self::ID_COLUMN]);

        foreach ($fields as $field) {
            self::addColumns($table, $field, '', $tables, $extra);
        }

        $table->addColumn(self::BLOB_COLUMN, Types::TEXT);

        return $table;
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
            self::addReferenceColumn($table, $field, $prefix, $tables);

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
     * Owned: a dedicated child table (not a join table), a back-pointer FK to the
     * owner, CASCADE, since a row here has no life apart from its one owner.
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

        $name = $ownerTable . '_' . $field->name;

        if ($field->ownership === Ownership::Shared) {
            $itemTable = self::tableOf($field->referencedShape, $tables);

            $join = new Table($name);
            $join->addColumn('owner_id', Types::INTEGER);
            $join->addColumn('item_id', Types::INTEGER);
            $join->setPrimaryKey(['owner_id', 'item_id']);
            $join->addForeignKeyConstraint($ownerTable, ['owner_id'], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);
            $join->addForeignKeyConstraint($itemTable, ['item_id'], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);

            return [$join];
        }

        $childExtra = [];
        $child = self::buildTable($name, PrototypeShape::ofClass($field->referencedShape), $tables, $childExtra);
        $child->addColumn('owner_id', Types::INTEGER);
        $child->addForeignKeyConstraint($ownerTable, ['owner_id'], [self::ID_COLUMN], ['onDelete' => 'CASCADE']);

        return [$child, ...$childExtra];
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
