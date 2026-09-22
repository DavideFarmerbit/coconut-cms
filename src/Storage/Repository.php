<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Connection;
use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Field\Ownership;

/**
 * Reads and writes instances of one native entity class, going through the
 * IdentityMap so the same row is never hydrated twice, and through FieldValidator plus
 * the friendly uniqueness pre-check before anything is written.
 *
 * Always operates on the whole Class Table Inheritance chain the class belongs to
 * (base first), one table per level, plus whatever join/child tables its reference and
 * collection fields need. A class with no native parent is simply a chain of one.
 *
 * A reference or collection field can only ever point at an already-persisted
 * instance, found() or just insert()-ed, since there's no id to store otherwise;
 * Phase 3's Changeset/TempId machinery is what lets a caller create and link several
 * entities in one atomic step instead.
 *
 * Update takes a plain field => value array of changes for now, a stand-in for the
 * real Changeset write path that Phase 3 replaces this with. Writing a collection
 * always replaces its whole set rather than diffing it, a simplification Phase 3's
 * real changeset removes.
 */
final class Repository
{
    /** @var class-string[] base first, ending with $class itself */
    private readonly array $chain;

    /** @var array<class-string, FieldDescriptor[]> each chain level's own fields */
    private readonly array $ownFieldsByLevel;

    /** @var FieldDescriptor[] every field across the whole chain */
    private readonly array $fields;

    /**
     * @param class-string $class the entity class this repository reads and writes
     * @param array<class-string, string> $tables entity class => table name, every chain level included
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly IdentityMap $identityMap,
        private readonly EntityManager $entityManager,
        private readonly string $class,
        private readonly array $tables,
    ) {
        $this->chain = PrototypeShape::chainOfClass($this->class);

        $ownFieldsByLevel = [];
        $fields = [];
        foreach ($this->chain as $level) {
            $ownFieldsByLevel[$level] = PrototypeShape::ownFieldsOfClass($level);
            array_push($fields, ...$ownFieldsByLevel[$level]);
        }

        $this->ownFieldsByLevel = $ownFieldsByLevel;
        $this->fields = $fields;
    }

    public function find(string $id): ?object
    {
        $cached = $this->identityMap->get($this->class, $id);
        if ($cached !== null) {
            return $cached;
        }

        $values = [];
        foreach ($this->chain as $level) {
            $row = $this->connection->fetchAssociative(
                sprintf('SELECT * FROM %s WHERE %s = ?', $this->table($level), SchemaBuilder::ID_COLUMN),
                [$id],
            );

            if ($row === false) {
                return null;
            }

            $levelFields = $this->ownFieldsByLevel[$level];
            $values = [...$values, ...RowMapper::fromRow($levelFields, $row)];

            foreach ($levelFields as $field) {
                if ($field->kind === FieldKind::EntityReference) {
                    $values[$field->name] = $this->readReference($field, $row);
                } elseif ($field->kind === FieldKind::Collection) {
                    $values[$field->name] = $this->readCollection($field, $id);
                }
            }
        }

        $entity = (new ReflectionClass($this->class))->newInstanceArgs($values);
        $this->identityMap->put($this->class, $id, $entity);

        return $entity;
    }

    /** @return string the new entity's id */
    public function insert(object $entity): string
    {
        $values = RowMapper::propertiesOf($entity, $this->fields);
        $this->validate($values);
        $this->assertUnique($values, null);

        $id = null;
        foreach ($this->chain as $level) {
            $row = $this->rowWithReferences($this->ownFieldsByLevel[$level], $values);

            if ($id === null) {
                $this->connection->insert($this->table($level), $row);
                $id = (string) $this->connection->lastInsertId();
            } else {
                $row[SchemaBuilder::ID_COLUMN] = $id;
                $this->connection->insert($this->table($level), $row);
            }
        }

        $this->writeCollections($values, $id);
        $this->identityMap->put($this->class, $id, $entity);

        return $id;
    }

    /**
     * @param array<string, mixed> $changes field name => new value, only the fields being changed
     * @return object the entity's new state
     */
    public function update(string $id, array $changes): object
    {
        $current = $this->find($id);
        if ($current === null) {
            throw new ValidationException(sprintf('Cannot update %s #%s, it does not exist.', $this->class, $id));
        }

        $values = [...RowMapper::propertiesOf($current, $this->fields), ...$changes];
        $this->validate($values);
        $this->assertUnique($values, $id);

        foreach ($this->chain as $level) {
            $row = $this->rowWithReferences($this->ownFieldsByLevel[$level], $values);
            $this->connection->update($this->table($level), $row, [SchemaBuilder::ID_COLUMN => $id]);
        }

        $this->writeCollections($values, $id);

        $entity = (new ReflectionClass($this->class))->newInstanceArgs($values);
        $this->identityMap->put($this->class, $id, $entity);

        return $entity;
    }

    /** Deleting the base row cascades through every derived level, and every Owned collection, automatically. */
    public function delete(string $id): void
    {
        $this->connection->delete($this->table($this->chain[0]), [SchemaBuilder::ID_COLUMN => $id]);
        $this->identityMap->forget($this->class, $id);
    }

    private function table(string $class): string
    {
        return $this->tables[$class] ?? throw new LogicException(sprintf('No table registered for %s.', $class));
    }

    /** @param array<string, mixed> $values */
    private function validate(array $values): void
    {
        foreach ($this->fields as $field) {
            foreach ($field->validators as $validator) {
                if (!$validator->validate($values[$field->name] ?? null)) {
                    throw new ValidationException(sprintf('Field "%s" is invalid.', $field->name));
                }
            }
        }
    }

    /**
     * The friendly half of uniqueness enforcement, the real UNIQUE constraint
     * SchemaBuilder already put on the column is the authoritative backstop.
     *
     * @param array<string, mixed> $values
     */
    private function assertUnique(array $values, ?string $excludingId): void
    {
        foreach ($this->fields as $field) {
            if (!$field->unique) {
                continue;
            }

            $column = $field->kind === FieldKind::EntityReference ? self::referenceColumn($field) : $field->name;
            $value = $field->kind === FieldKind::EntityReference
                ? $this->referencedId($field, $values[$field->name])
                : $values[$field->name];

            $sql = sprintf('SELECT 1 FROM %s WHERE %s = ?', $this->declaringTable($field), $column);
            $params = [$value];

            if ($excludingId !== null) {
                $sql .= sprintf(' AND %s != ?', SchemaBuilder::ID_COLUMN);
                $params[] = $excludingId;
            }

            if ($this->connection->fetchOne($sql, $params) !== false) {
                throw new ValidationException(sprintf('"%s" is already taken for field "%s".', $value, $field->name));
            }
        }
    }

    /**
     * @param FieldDescriptor[] $levelFields
     * @param array<string, mixed> $values
     */
    private function rowWithReferences(array $levelFields, array $values): array
    {
        $row = RowMapper::toRow($levelFields, $values);

        foreach ($levelFields as $field) {
            if ($field->kind === FieldKind::EntityReference) {
                $row[self::referenceColumn($field)] = $this->referencedId($field, $values[$field->name]);
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $values */
    private function writeCollections(array $values, string $ownerId): void
    {
        foreach ($this->fields as $field) {
            if ($field->kind === FieldKind::Collection) {
                $this->writeCollection($field, $ownerId, $values[$field->name] ?? []);
            }
        }
    }

    private static function referenceColumn(FieldDescriptor $field): string
    {
        return $field->name . '_id';
    }

    /** The id of an already-persisted reference target. */
    private function referencedId(FieldDescriptor $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->identityMap->idOf($value)
            ?? throw new ValidationException(sprintf('Field "%s" must reference an already-persisted %s.', $field->name, $field->referencedShape));
    }

    private function readReference(FieldDescriptor $field, array $row): ?object
    {
        $id = $row[self::referenceColumn($field)] ?? null;
        if ($id === null) {
            return null;
        }

        return $this->entityManager->repository($field->referencedShape)->find((string) $id);
    }

    /** The table of whichever chain level actually declared $field, not always the leaf's own table. */
    private function declaringTable(FieldDescriptor $field): string
    {
        foreach ($this->ownFieldsByLevel as $level => $levelFields) {
            foreach ($levelFields as $candidate) {
                if ($candidate->name === $field->name) {
                    return $this->table($level);
                }
            }
        }

        throw new LogicException(sprintf('Field "%s" was not found on any level of %s.', $field->name, $this->class));
    }

    private function collectionTable(FieldDescriptor $field): string
    {
        return $this->declaringTable($field) . '_' . $field->name;
    }

    /** @return object[] */
    private function readCollection(FieldDescriptor $field, string $ownerId): array
    {
        $table = $this->collectionTable($field);

        if ($field->ownership === Ownership::Shared) {
            $itemIds = $this->connection->fetchFirstColumn(sprintf('SELECT item_id FROM %s WHERE owner_id = ?', $table), [$ownerId]);
            $itemRepository = $this->entityManager->repository($field->referencedShape);

            return array_map(static fn (int|string $itemId): object => $itemRepository->find((string) $itemId), $itemIds);
        }

        // Owned: no independent repository, hydrate the child rows directly instead
        $rows = $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE owner_id = ?', $table), [$ownerId]);
        $itemFields = PrototypeShape::ofClass($field->referencedShape);

        return array_map(
            static fn (array $row): object => (new ReflectionClass($field->referencedShape))->newInstanceArgs(RowMapper::fromRow($itemFields, $row)),
            $rows,
        );
    }

    /** @param object[] $items */
    private function writeCollection(FieldDescriptor $field, string $ownerId, array $items): void
    {
        $table = $this->collectionTable($field);

        // Replaces the whole set rather than diffing it, correct but not minimal, see class docblock.
        $this->connection->delete($table, ['owner_id' => $ownerId]);

        if ($field->ownership === Ownership::Shared) {
            foreach ($items as $item) {
                $itemId = $this->identityMap->idOf($item)
                    ?? throw new ValidationException(sprintf('Field "%s" must reference already-persisted %s instances.', $field->name, $field->referencedShape));

                $this->connection->insert($table, ['owner_id' => $ownerId, 'item_id' => $itemId]);
            }

            return;
        }

        $itemFields = PrototypeShape::ofClass($field->referencedShape);
        foreach ($items as $item) {
            $row = RowMapper::toRow($itemFields, RowMapper::propertiesOf($item, $itemFields));
            $row['owner_id'] = $ownerId;
            $this->connection->insert($table, $row);
        }
    }
}
