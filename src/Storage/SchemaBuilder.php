<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use LogicException;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;

/**
 * Translates a FieldDescriptor[] shape into a Doctrine DBAL Table: a real column for
 * every queryable field, and one JSON blob column for everything else.
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
     * @param FieldDescriptor[] $fields
     */
    public static function tableFor(string $tableName, array $fields): Table
    {
        $table = new Table($tableName);
        $table->addColumn(self::ID_COLUMN, Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey([self::ID_COLUMN]);

        foreach ($fields as $field) {
            self::addColumns($table, $field, '');
        }

        $table->addColumn(self::BLOB_COLUMN, Types::TEXT);

        return $table;
    }

    private static function addColumns(Table $table, FieldDescriptor $field, string $prefix): void
    {
        if ($field->kind === FieldKind::EmbeddedValueObject) {
            foreach (PrototypeShape::ofClass($field->referencedShape) as $nested) {
                self::addColumns($table, $nested, $prefix . $field->name . '_');
            }

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
