<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValues;

/**
 * Turns level layouts into the Doctrine schema of every table they need:
 * the shared entities table, then each level's table, side table and
 * Collection tables. Rejects two things landing on one name, and any name
 * too long for MySQL, rather than letting the database fail later.
 */
final class DatabaseSchema
{
    public const ENTITIES = 'entities';

    /**
     * MySQL's limit on table, column and index names, 64, less the six
     * characters of ColumnNames::OLD: renamed by a migration, a name must
     * still fit.
     */
    private const MAX_NAME_LENGTH = 64 - 6;

    /**
     * A Collection item's position. Not INTEGER: Doctrine reads an INTEGER
     * column back from SQLite as auto-incremented whenever it's the only
     * integer in the primary key, so every migration would see it changed.
     */
    private const POSITION_TYPE = Types::BIGINT;

    /** @param list<LevelLayout> $levels */
    public static function of(array $levels): Schema {
        $schema = new Schema();
        self::addEntitiesTable($schema);

        foreach ($levels as $level) {
            self::addLevel($schema, $level);
        }

        return $schema;
    }

    private static function addEntitiesTable(Schema $schema): void {
        $table = $schema->createTable(self::ENTITIES);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('owner', Types::GUID, ['default' => EntityIds::NIL]);
        $table->addColumn('owner_field', Types::STRING, ['length' => 255, 'default' => '']);
        $table->addColumn('position', Types::INTEGER, ['default' => -1]);
        $table->addColumn('concrete_identifier', Types::STRING, ['length' => 255]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['owner', 'owner_field', 'position']);
        $table->addForeignKeyConstraint(self::ENTITIES, ['owner'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    private static function addLevel(Schema $schema, LevelLayout $level): void {
        $table = self::createTable($schema, $level->table, $level->identifier);
        $table->addColumn(ColumnNames::ID, Types::GUID);
        $table->addColumn(ColumnNames::OWNER, Types::GUID, ['default' => EntityIds::NIL]);
        $table->addColumn(ColumnNames::OWNER_FIELD, Types::STRING, ['length' => 255, 'default' => '']);
        $table->setPrimaryKey([ColumnNames::ID]);
        $table->addForeignKeyConstraint(self::ENTITIES, [ColumnNames::ID], ['id'], ['onDelete' => 'CASCADE']);

        foreach ($level->columns as $column) {
            self::addValueColumn($table, $column);
        }

        foreach ($level->owned as $owned) {
            if ($owned->countColumn !== null) {
                self::addColumn($table, $owned->countColumn, Types::INTEGER, ['default' => 0]);
            }
        }

        foreach ($level->uniqueIndexes as $columns) {
            $table->addUniqueIndex($columns);
        }

        $state = self::createTable($schema, $level->stateTable, $level->identifier);
        $state->addColumn(ColumnNames::ID, Types::GUID);
        $state->setPrimaryKey([ColumnNames::ID]);
        $state->addForeignKeyConstraint($level->table, [ColumnNames::ID], [ColumnNames::ID], ['onDelete' => 'CASCADE']);
        foreach ($level->stateColumns() as $name) {
            self::addColumn($state, $name, Types::BOOLEAN, ['default' => false]);
        }

        foreach ($level->collections as $collection) {
            self::addCollection($schema, $level, $collection);
        }
    }

    private static function addCollection(Schema $schema, LevelLayout $level, CollectionLayout $collection): void {
        $table = self::createTable($schema, $collection->table, $level->identifier);
        $table->addColumn(ColumnNames::OWNER, Types::GUID);
        $table->addColumn(ColumnNames::POSITION, self::POSITION_TYPE);
        $table->setPrimaryKey([ColumnNames::OWNER, ColumnNames::POSITION]);
        $table->addForeignKeyConstraint($level->table, [ColumnNames::OWNER], [ColumnNames::ID], ['onDelete' => 'CASCADE']);

        foreach ($collection->itemColumns as $column) {
            self::addValueColumn($table, $column);
        }

        foreach ($collection->uniqueIndexes as $columns) {
            $table->addUniqueIndex($columns);
        }

        if ($collection->stateTable === null) {
            return;
        }

        $state = self::createTable($schema, $collection->stateTable, $level->identifier);
        $state->addColumn(ColumnNames::OWNER, Types::GUID);
        $state->addColumn(ColumnNames::POSITION, self::POSITION_TYPE);
        $state->setPrimaryKey([ColumnNames::OWNER, ColumnNames::POSITION]);
        $state->addForeignKeyConstraint($collection->table, [ColumnNames::OWNER, ColumnNames::POSITION], [ColumnNames::OWNER, ColumnNames::POSITION], ['onDelete' => 'CASCADE']);
        foreach ($collection->itemColumns as $column) {
            self::addColumn($state, $column->name, Types::BOOLEAN, ['default' => false]);
        }
    }

    /**
     * Every value column is nullable, whatever its field: a migration's move
     * rewrites rows whole in the new shape, giving no value to a column on its
     * way out. The code guarantees a field that can't hold null never does.
     */
    private static function addValueColumn(Table $table, ValueColumn $column): void {
        [$type, $options] = self::typeOf($column->field);
        self::addColumn($table, $column->name, $type, ['notnull' => false, ...$options]);

        if ($column->field->kind() === FieldKind::Reference) {
            $table->addForeignKeyConstraint(self::ENTITIES, [$column->name], ['id'], ['onDelete' => 'SET NULL']);
        }
    }

    /**
     * The Doctrine type and options of the column holding $field's value.
     * @return array{string, array<string, mixed>}
     */
    private static function typeOf(FieldDescriptor $field): array {
        return match ($field->kind()) {
            FieldKind::Scalar => self::scalarTypeOf($field),
            FieldKind::ValueObject => $field->indexable() ? [Types::STRING, ['length' => StorableValues::MAX_INDEXABLE_LENGTH]] : [Types::TEXT, []],
            FieldKind::Reference => [Types::GUID, []],
            default => throw new InvalidArgumentException(sprintf('A %s field has no column of its own.', $field->kind()->name)),
        };
    }

    /** @return array{string, array<string, mixed>} */
    private static function scalarTypeOf(FieldDescriptor $field): array {
        return match ($field->scalarType()) {
            ScalarType::String => [Types::STRING, ['length' => ScalarValues::MAX_STRING_LENGTH]],
            ScalarType::Text => [Types::TEXT, []],
            ScalarType::Int => [Types::BIGINT, []],
            ScalarType::Bool => [Types::BOOLEAN, []],
            ScalarType::Float => [Types::FLOAT, []],
            ScalarType::Date => [Types::DATE_IMMUTABLE, []],
            ScalarType::DateTime => [Types::DATETIME_IMMUTABLE, []],
            ScalarType::Decimal => [Types::DECIMAL, ['precision' => ScalarValues::DECIMAL_PRECISION, 'scale' => ScalarValues::DECIMAL_SCALE]],
            ScalarType::Enum => $field->enumBacking() === ScalarType::Int ? [Types::BIGINT, []] : [Types::STRING, ['length' => ScalarValues::MAX_STRING_LENGTH]],
        };
    }

    /** @param string $identifier The entity type the table is for, named if it fails. */
    private static function createTable(Schema $schema, string $name, string $identifier): Table {
        self::assertFits($name, sprintf('Table "%s" of %s', $name, $identifier));

        if ($schema->hasTable($name)) {
            throw new InvalidArgumentException(sprintf('%s needs a table named "%s", which already exists.', $identifier, $name));
        }

        return $schema->createTable($name);
    }

    /** @param array<string, mixed> $options */
    private static function addColumn(Table $table, string $name, string $type, array $options): void {
        self::assertFits($name, sprintf('Column "%s" on table "%s"', $name, $table->getName()));

        if ($table->hasColumn($name)) {
            throw new InvalidArgumentException(sprintf('Two values both land on column "%s" of table "%s".', $name, $table->getName()));
        }

        $table->addColumn($name, $type, $options);
    }

    private static function assertFits(string $name, string $what): void {
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('%s is longer than %d characters.', $what, self::MAX_NAME_LENGTH));
        }
    }
}
