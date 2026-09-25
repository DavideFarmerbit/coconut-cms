<?php

namespace XyloIsCoding\CoconutCms\Storage\Prototype;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use JsonException;
use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Field\Ownership;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;

/**
 * The persisted definitions of every editor-created prototype: its name, its parent
 * (native or itself editor-created), and its own fields, built into FieldDescriptor
 * objects from stored rows rather than reflection, the editor-assembled half of the
 * two-sources principle.
 *
 * An identifier is either a real PHP class-string (native, resolved via reflection
 * through PrototypeShape) or an editor-created prototype's own name (resolved from
 * these tables), `class_exists()` is the test that tells the two apart. A chain can
 * freely mix both kinds at any depth.
 *
 * This is fixed, hand-designed infrastructure, not a FieldDescriptor-described shape
 * itself, it's the thing that makes that machinery work for the editor-assembled
 * source, so it's exempt from it.
 */
final class PrototypeRegistry
{
    public const string PROTOTYPES_TABLE = 'prototypes';
    public const string FIELDS_TABLE = 'prototype_fields';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** @return Table[] synced once via SchemaSynchronizer, the same as any other table */
    public static function schemaTables(): array
    {
        $prototypes = new Table(self::PROTOTYPES_TABLE);
        $prototypes->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $prototypes->addColumn('name', Types::STRING);
        $prototypes->addColumn('parent', Types::STRING, ['notnull' => false]);
        $prototypes->setPrimaryKey(['id']);
        $prototypes->addUniqueIndex(['name']);

        $fields = new Table(self::FIELDS_TABLE);
        $fields->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $fields->addColumn('prototype_id', Types::INTEGER);
        $fields->addColumn('name', Types::STRING);
        $fields->addColumn('kind', Types::STRING);
        $fields->addColumn('label', Types::STRING);
        $fields->addColumn('group_name', Types::STRING, ['notnull' => false]);
        $fields->addColumn('queryable', Types::BOOLEAN);
        $fields->addColumn('unique_flag', Types::BOOLEAN);
        $fields->addColumn('referenced_shape', Types::STRING, ['notnull' => false]);
        $fields->addColumn('collection_item_kind', Types::STRING, ['notnull' => false]);
        $fields->addColumn('ownership', Types::STRING, ['notnull' => false]);
        $fields->addColumn('choice_options', Types::TEXT, ['notnull' => false]);
        $fields->setPrimaryKey(['id']);
        $fields->addForeignKeyConstraint(self::PROTOTYPES_TABLE, ['prototype_id'], ['id'], ['onDelete' => 'CASCADE']);

        return [$prototypes, $fields];
    }

    public function isEditorCreated(string $identifier): bool
    {
        return !class_exists($identifier);
    }

    public function exists(string $name): bool
    {
        return $this->connection->fetchOne(sprintf('SELECT 1 FROM %s WHERE name = ?', self::PROTOTYPES_TABLE), [$name]) !== false;
    }

    /**
     * Renames an editor-created prototype's own identity row, cascading to anything
     * that stored the old name as its parent or a field's referenced shape. Raw
     * storage only, no permission check or table rename, that's SchemaEditor's job.
     */
    public function rename(string $oldName, string $newName): void
    {
        $this->connection->update(self::PROTOTYPES_TABLE, ['name' => $newName], ['name' => $oldName]);
        $this->renameReferences($oldName, $newName);
    }

    /**
     * Updates every stored reference to $oldIdentifier, as a prototype's parent or a
     * field's referenced shape, to $newIdentifier instead. Shared by an editor-created
     * prototype's own rename() above and a native class's rename fixup
     * (EntityRegistrar::rename()), the meta-schema doesn't distinguish where an
     * identifier it's storing came from.
     */
    public function renameReferences(string $oldIdentifier, string $newIdentifier): void
    {
        $this->connection->update(self::PROTOTYPES_TABLE, ['parent' => $newIdentifier], ['parent' => $oldIdentifier]);
        $this->connection->update(self::FIELDS_TABLE, ['referenced_shape' => $newIdentifier], ['referenced_shape' => $oldIdentifier]);
    }

    /** Persists a fresh prototype definition. Raw storage only, no permission check, that's SchemaEditor's job. */
    public function define(string $name, ?string $parent): void
    {
        if (class_exists($name)) {
            throw new LogicException(sprintf('"%s" collides with a real PHP class name.', $name));
        }

        $this->connection->insert(self::PROTOTYPES_TABLE, ['name' => $name, 'parent' => $parent]);
    }

    /** Raw storage only, no permission check or DDL, that's SchemaEditor's job. */
    public function addField(string $identifier, FieldDescriptor $field): void
    {
        $this->connection->insert(self::FIELDS_TABLE, [...['prototype_id' => $this->idOf($identifier)], ...self::rowOf($field)]);
    }

    /** Raw storage only, no permission check or DDL, that's SchemaEditor's job. */
    public function removeField(string $identifier, string $fieldName): void
    {
        $this->connection->delete(self::FIELDS_TABLE, ['prototype_id' => $this->idOf($identifier), 'name' => $fieldName]);
    }

    public function parentOf(string $identifier): ?string
    {
        if (!$this->isEditorCreated($identifier)) {
            $parent = (new ReflectionClass($identifier))->getParentClass();

            return $parent === false ? null : $parent->getName();
        }

        $row = $this->connection->fetchAssociative(sprintf('SELECT parent FROM %s WHERE name = ?', self::PROTOTYPES_TABLE), [$identifier]);
        if ($row === false) {
            throw new LogicException(sprintf('Unknown prototype "%s".', $identifier));
        }

        return $row['parent'];
    }

    /** @return string[] base first, ending with $identifier itself, mixing native and editor-created levels freely */
    public function chainOf(string $identifier): array
    {
        $chain = [$identifier];

        $parent = $this->parentOf($identifier);
        while ($parent !== null) {
            array_unshift($chain, $parent);
            $parent = $this->parentOf($parent);
        }

        return $chain;
    }

    /** @return FieldDescriptor[] the full effective shape, every level's own fields concatenated, base first */
    public function fieldsOf(string $identifier): array
    {
        $fields = [];
        foreach ($this->chainOf($identifier) as $level) {
            array_push($fields, ...$this->ownFieldsOf($level));
        }

        return $fields;
    }

    /** @return FieldDescriptor[] only the fields declared at this exact level */
    public function ownFieldsOf(string $identifier): array
    {
        if (!$this->isEditorCreated($identifier)) {
            return PrototypeShape::ownFieldsOfClass($identifier);
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE prototype_id = ?', self::FIELDS_TABLE),
            [$this->idOf($identifier)],
        );

        return array_map(self::fieldFromRow(...), $rows);
    }

    /** The nearest native ancestor in the chain, walking up from $identifier, itself included. */
    public function nearestNativeAncestor(string $identifier): ?string
    {
        foreach (array_reverse($this->chainOf($identifier)) as $level) {
            if (!$this->isEditorCreated($level)) {
                return $level;
            }
        }

        return null;
    }

    private function idOf(string $name): int
    {
        $id = $this->connection->fetchOne(sprintf('SELECT id FROM %s WHERE name = ?', self::PROTOTYPES_TABLE), [$name]);

        return $id === false ? throw new LogicException(sprintf('Unknown prototype "%s".', $name)) : (int) $id;
    }

    /** @return array<string, mixed> */
    private static function rowOf(FieldDescriptor $field): array
    {
        return [
            'name' => $field->name,
            'kind' => $field->kind->value,
            'label' => $field->label,
            'group_name' => $field->group,
            'queryable' => $field->queryable,
            'unique_flag' => $field->unique,
            'referenced_shape' => $field->referencedShape,
            'collection_item_kind' => $field->collectionItemKind?->value,
            'ownership' => $field->ownership?->value,
            'choice_options' => $field->choiceOptions === null ? null : self::encode($field->choiceOptions),
        ];
    }

    /**
     * Editor-created fields always come back with no validators and no permission,
     * both are behavior objects, not data, and neither has a descriptor-to-object
     * deserialization scheme built yet, same open problem, same scoping call for both.
     *
     * @param array<string, mixed> $row
     */
    private static function fieldFromRow(array $row): FieldDescriptor
    {
        $kind = FieldKind::from($row['kind']);
        $label = $row['label'];
        $group = $row['group_name'];
        $queryable = (bool) $row['queryable'];
        $unique = (bool) $row['unique_flag'];
        $validators = [];

        return match ($kind) {
            FieldKind::Choice => FieldDescriptor::choice($row['name'], self::decode($row['choice_options']), $label, $group, $queryable, $validators),
            FieldKind::EmbeddedValueObject => FieldDescriptor::embed($row['name'], $row['referenced_shape'], $label, $group, $validators),
            FieldKind::EntityReference => FieldDescriptor::reference($row['name'], $row['referenced_shape'], Ownership::from($row['ownership']), $label, $group, $queryable, $unique, $validators),
            FieldKind::Collection => FieldDescriptor::collection(
                $row['name'],
                FieldKind::from($row['collection_item_kind']),
                $row['referenced_shape'],
                $label,
                $group,
                $row['ownership'] === null ? null : Ownership::from($row['ownership']),
                $validators,
            ),
            default => FieldDescriptor::scalar($row['name'], $kind, $label, $group, $queryable, $unique, $validators),
        };
    }

    /** @param mixed[] $options */
    private static function encode(array $options): string
    {
        try {
            return json_encode($options, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonException(sprintf('Could not encode choice options as JSON: %s', $exception->getMessage()), previous: $exception);
        }
    }

    /** @return mixed[] */
    private static function decode(string $options): array
    {
        try {
            /** @var mixed[] $decoded */
            $decoded = json_decode($options, associative: true, flags: JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException $exception) {
            throw new JsonException(sprintf('Could not decode choice options from JSON: %s', $exception->getMessage()), previous: $exception);
        }
    }
}
