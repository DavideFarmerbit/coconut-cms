<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use ReflectionEnum;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * Turns level layouts into the Doctrine schema of every table they need:
 * the shared entities table, then each level's table, side table and
 * Collection tables. Rejects two things landing on one name, and any name
 * too long for MySQL, rather than letting the database fail later.
 */
final class DatabaseSchema
{
    public const ENTITIES = 'entities';

    /** The id of the seeded Cms row: every row without a real owner points at it. */
    public const NO_OWNER = '00000000-0000-0000-0000-000000000000';

    /** MySQL's limit on table, column and index names. */
    private const MAX_NAME_LENGTH = 64;

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
        $table->addColumn('owner', Types::GUID, ['default' => self::NO_OWNER]);
        $table->addColumn('owner_field', Types::STRING, ['length' => 255, 'default' => '']);
        $table->addColumn('position', Types::INTEGER, ['default' => -1]);
        $table->addColumn('concrete_identifier', Types::STRING, ['length' => 255]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['owner', 'owner_field', 'position']);
        $table->addForeignKeyConstraint(self::ENTITIES, ['owner'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    private static function addLevel(Schema $schema, LevelLayout $level): void {
        $table = self::createTable($schema, $level->table, $level->identifier);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('owner', Types::GUID, ['default' => self::NO_OWNER]);
        $table->addColumn('owner_field', Types::STRING, ['length' => 255, 'default' => '']);
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint(self::ENTITIES, ['id'], ['id'], ['onDelete' => 'CASCADE']);

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
        $state->addColumn('id', Types::GUID);
        $state->setPrimaryKey(['id']);
        $state->addForeignKeyConstraint($level->table, ['id'], ['id'], ['onDelete' => 'CASCADE']);
        foreach ($level->stateColumns() as $name) {
            self::addColumn($state, $name, Types::BOOLEAN, ['default' => false]);
        }

        foreach ($level->collections as $collection) {
            self::addCollection($schema, $level, $collection);
        }
    }

    private static function addCollection(Schema $schema, LevelLayout $level, CollectionLayout $collection): void {
        $table = self::createTable($schema, $collection->table, $level->identifier);
        $table->addColumn('owner', Types::GUID);
        $table->addColumn('position', Types::INTEGER);
        $table->setPrimaryKey(['owner', 'position']);
        $table->addForeignKeyConstraint($level->table, ['owner'], ['id'], ['onDelete' => 'CASCADE']);

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
        $state->addColumn('owner', Types::GUID);
        $state->addColumn('position', Types::INTEGER);
        $state->setPrimaryKey(['owner', 'position']);
        $state->addForeignKeyConstraint($collection->table, ['owner', 'position'], ['owner', 'position'], ['onDelete' => 'CASCADE']);
        foreach ($collection->itemColumns as $column) {
            self::addColumn($state, $column->name, Types::BOOLEAN, ['default' => false]);
        }
    }

    /** Every content column is nullable: an untouched value is null, and resolves to its default. */
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
            FieldKind::ValueObject => $field->indexable() ? [Types::STRING, ['length' => 255]] : [Types::TEXT, []],
            FieldKind::Reference => [Types::GUID, []],
            default => throw new InvalidArgumentException(sprintf('A %s field has no column of its own.', $field->kind()->name)),
        };
    }

    /** @return array{string, array<string, mixed>} */
    private static function scalarTypeOf(FieldDescriptor $field): array {
        return match ($field->scalarType()) {
            ScalarType::String => [Types::STRING, ['length' => 255]],
            ScalarType::Text => [Types::TEXT, []],
            ScalarType::Int => [Types::BIGINT, []],
            ScalarType::Bool => [Types::BOOLEAN, []],
            ScalarType::Float => [Types::FLOAT, []],
            ScalarType::Date => [Types::DATE_IMMUTABLE, []],
            ScalarType::DateTime => [Types::DATETIME_IMMUTABLE, []],
            ScalarType::Decimal => [Types::DECIMAL, ['precision' => 20, 'scale' => 6]],
            ScalarType::Enum => (new ReflectionEnum($field->enumClass()))->getBackingType()?->getName() === 'int'
                ? [Types::BIGINT, []]
                : [Types::STRING, ['length' => 255]],
        };
    }

    /** @param class-string $identifier The class the table is for, named if it fails. */
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
