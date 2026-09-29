<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Attributes\Ownership;

/**
 * Reads and writes instances of one identifier, a native class-string or an
 * editor-created prototype's own name, going through the IdentityMap so the same row
 * is never hydrated twice, and through FieldValidator plus the friendly uniqueness
 * pre-check before anything is written. Shape resolution and instantiation both go
 * through EntityManager's PrototypeRegistry front door, never PrototypeShape or
 * reflection directly, so either kind of identifier works the same way here.
 *
 * A Shared reference or collection item reached while hydrating another entity is a
 * lazy PHP 8.4 ghost object (see lazyFind()), not eagerly resolved, an owner holding
 * many of these never pays for hydrating any of them until something actually touches
 * one. A direct find() call still resolves immediately, a caller asking for something
 * by id wants it now.
 *
 * Always operates on the whole Class Table Inheritance chain the identifier belongs to
 * (base first), one table per level, plus whatever join/child tables its reference and
 * collection fields need. An identifier with no parent is simply a chain of one, on top
 * of the shared entities table every chain roots on (Phase 8): that's where insert()
 * actually originates an id and delete() actually removes a row, find()/lazyFind()
 * resolve entities.concrete_type first so a base-typed lookup or reference hydrates as
 * whatever concrete subtype the row actually is.
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
    /** @var string[] base first, ending with $class itself */
    private readonly array $chain;

    /** @var array<string, FieldDescriptor[]> each chain level's own fields */
    private readonly array $ownFieldsByLevel;

    /** @var FieldDescriptor[] every field across the whole chain */
    private readonly array $fields;

    /** @var PrototypeValidator[] every cross-field rule across the whole chain */
    private readonly array $prototypeValidators;

    /**
     * @param string $class the identifier this repository reads and writes, native or editor-created
     * @param array<string, string> $tables identifier => table name, every chain level included
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly IdentityMap $identityMap,
        private readonly EntityManager $entityManager,
        private readonly string $class,
        private readonly array $tables,
    ) {
        $this->chain = $this->entityManager->chainOf($this->class);

        $ownFieldsByLevel = [];
        $fields = [];
        foreach ($this->chain as $level) {
            $ownFieldsByLevel[$level] = $this->entityManager->ownFieldsOf($level);
            array_push($fields, ...$ownFieldsByLevel[$level]);
        }

        $this->ownFieldsByLevel = $ownFieldsByLevel;
        $this->fields = $fields;
        $this->prototypeValidators = $this->entityManager->prototypeValidatorsOf($this->class);
    }

    public function find(string $id): ?object
    {
        $cached = $this->identityMap->get($this->class, $id);
        if ($cached !== null) {
            return $cached;
        }

        $concreteType = $this->entityManager->concreteIdentifierOf($id);
        if ($concreteType === null) {
            return null;
        }
        if ($concreteType !== $this->class) {
            return $this->entityManager->repository($concreteType)->find($id);
        }

        $values = $this->hydrateValues($id);

        return $values === null ? null : $this->instantiateAndCache($id, $values);
    }

    /**
     * Materializes a row Query already fetched through its own single multi-table
     * JOIN across the whole chain, instead of this Repository's own one-query-per-
     * level find(), which would be an N+1 disaster for a list of rows. Reuses the
     * exact same reference/collection resolution and Identity Map integration find()
     * has, a query-fetched entity behaves identically to a directly-found one.
     *
     * @param array<string, array<string, mixed>> $rowsByLevel each chain level's own raw row
     */
    public function materialize(string $id, array $rowsByLevel): object
    {
        $cached = $this->identityMap->get($this->class, $id);
        if ($cached !== null) {
            return $cached;
        }

        $values = [];
        foreach ($this->chain as $level) {
            $values = [...$values, ...$this->valuesForLevel($level, $rowsByLevel[$level], $id)];
        }

        return $this->instantiateAndCache($id, $values);
    }

    /**
     * Batch-fetches several ids of this exact identifier at once: one query per chain
     * level (WHERE id IN (...)), not one per id, the same "one query per level" cost
     * find() already pays for a single id, now shared across however many ids are in
     * the batch. Used by Query::hydrateConcreteTypes() to resolve a page's
     * foreign-subtype rows without one lookup per row, see ROADMAP.md "Step B Fix 1".
     * Every id here must already be known to be this exact identifier's concrete type,
     * unlike find(), this never resolves or delegates polymorphically itself.
     *
     * @param string[] $ids
     * @return array<string, object> id => hydrated instance, missing entries for any id
     *   that no longer exists, the batch equivalent of find() returning null
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $result = [];
        $remaining = [];
        foreach ($ids as $id) {
            $cached = $this->identityMap->get($this->class, $id);
            if ($cached !== null) {
                $result[$id] = $cached;
            } else {
                $remaining[] = $id;
            }
        }

        if ($remaining === []) {
            return $result;
        }

        $rowsByIdAndLevel = [];
        foreach ($this->chain as $level) {
            $rows = $this->connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE %s IN (?)', $this->table($level), SchemaBuilder::ID_COLUMN),
                [$remaining],
                [ArrayParameterType::STRING],
            );

            foreach ($rows as $row) {
                $rowsByIdAndLevel[(string) $row[SchemaBuilder::ID_COLUMN]][$level] = $row;
            }
        }

        foreach ($remaining as $id) {
            if (!isset($rowsByIdAndLevel[$id]) || count($rowsByIdAndLevel[$id]) !== count($this->chain)) {
                continue;
            }

            $result[$id] = $this->materialize($id, $rowsByIdAndLevel[$id]);
        }

        return $result;
    }

    /** @param array<string, mixed> $values */
    private function instantiateAndCache(string $id, array $values): object
    {
        $entity = $this->entityManager->instantiate($this->class, $values);
        $this->identityMap->put($this->class, $id, $entity);

        return $entity;
    }

    /**
     * A lazy reference to this entity: the query doesn't run until a property is
     * actually touched, so an owner holding many of these (a Shared reference or
     * collection item) never eagerly hydrates them just because the owner itself was
     * loaded. Falls back to whatever's already in the IdentityMap, real or another
     * still-untouched ghost, so "the same id resolves to the same instance" keeps
     * holding regardless of how many places reach for it before anything triggers it.
     *
     * Still resolves concrete_type eagerly, a lazy ghost has to be built as a real
     * instance of some concrete class, PHP's lazy-ghost API leaves no way to defer that
     * part. One cheap indexed lookup, not the full row, the "every entity read now
     * joins to entities" cost ARCHITECTURE.md accepts, not a laziness regression.
     */
    public function lazyFind(string $id): object
    {
        $cached = $this->identityMap->get($this->class, $id);
        if ($cached !== null) {
            return $cached;
        }

        $concreteType = $this->entityManager->concreteIdentifierOf($id) ?? $this->class;
        if ($concreteType !== $this->class) {
            return $this->entityManager->repository($concreteType)->lazyFind($id);
        }

        $ghost = $this->entityManager->isEditorCreated($this->class)
            ? $this->lazyDynamicEntity($id)
            : $this->lazyNativeGhost($id);

        $this->identityMap->put($this->class, $id, $ghost);

        return $ghost;
    }

    /** A lazy ghost of $this->class itself, a real, reflectable native class. */
    private function lazyNativeGhost(string $id): object
    {
        $reflection = new ReflectionClass($this->class);

        return $reflection->newLazyGhost(function (object $ghost) use ($reflection, $id): void {
            $values = $this->hydrateValues($id)
                ?? throw new LogicException(sprintf('%s #%s no longer exists, a reference should never dangle.', $this->class, $id));

            foreach ($values as $name => $value) {
                $reflection->getProperty($name)->setRawValueWithoutLazyInitialization($ghost, $value);
            }
        });
    }

    /**
     * $this->class has no class of its own to reflect on (it's an editor-created
     * name), so the lazy ghost is of DynamicEntity itself instead, with its own
     * identifier and values properties filled in lazily.
     */
    private function lazyDynamicEntity(string $id): DynamicEntity
    {
        $reflection = new ReflectionClass(DynamicEntity::class);

        return $reflection->newLazyGhost(function (DynamicEntity $ghost) use ($reflection, $id): void {
            $values = $this->hydrateValues($id)
                ?? throw new LogicException(sprintf('%s #%s no longer exists, a reference should never dangle.', $this->class, $id));

            $reflection->getProperty('identifier')->setRawValueWithoutLazyInitialization($ghost, $this->class);
            $reflection->getProperty('values')->setRawValueWithoutLazyInitialization($ghost, $values);
        });
    }

    /**
     * Everything find()/lazyFind() need to construct or populate an instance, kept
     * separate so a lazy ghost's initializer can fill itself in with the exact same
     * logic find() uses to build constructor args, without going through find() and
     * its own IdentityMap check again. Fetches its own rows one level at a time;
     * materialize() is the counterpart for rows already fetched some other way.
     *
     * @return array<string, mixed>|null null if the row no longer exists
     */
    private function hydrateValues(string $id): ?array
    {
        $values = [];
        foreach ($this->chain as $level) {
            $row = $this->connection->fetchAssociative(
                sprintf('SELECT * FROM %s WHERE %s = ?', $this->table($level), SchemaBuilder::ID_COLUMN),
                [$id],
            );

            if ($row === false) {
                return null;
            }

            $values = [...$values, ...$this->valuesForLevel($level, $row, $id)];
        }

        return $values;
    }

    /**
     * One chain level's own field values out of its own raw row: RowMapper's
     * queryable-or-blob split, plus resolving whatever RowMapper skips entirely
     * (EntityReference, Collection), those live in their own FK column or join/child
     * table, not this row.
     *
     * @param array<string, mixed> $row this level's own raw row
     * @return array<string, mixed>
     */
    private function valuesForLevel(string $level, array $row, string $id): array
    {
        $levelFields = $this->ownFieldsByLevel[$level];
        $values = RowMapper::fromRow($levelFields, $row);

        foreach ($levelFields as $field) {
            if ($field->kind === FieldKind::EntityReference) {
                $values[$field->name] = $this->readReference($field, $row);
            } elseif ($field->kind === FieldKind::Collection) {
                $values[$field->name] = $this->readCollection($field, $id);
            }
        }

        return $values;
    }

    /**
     * @param string|null $explicitId reuse this id instead of generating a fresh one,
     *   only for restoring a previously deleted entity so anything still referencing
     *   its old id keeps working
     * @return string the new entity's id
     */
    public function insert(object $entity, ?string $explicitId = null): string
    {
        $values = RowMapper::propertiesOf($entity, $this->fields);
        $this->validate($values);
        $this->assertUnique($values, null);

        $entitiesRow = ['concrete_type' => $this->class];
        if ($explicitId !== null) {
            $entitiesRow[SchemaBuilder::ID_COLUMN] = $explicitId;
        }
        $this->connection->insert(SchemaBuilder::ENTITIES_TABLE, $entitiesRow);
        $id = $explicitId ?? (string) $this->connection->lastInsertId();

        foreach ($this->chain as $level) {
            $row = $this->rowWithReferences($this->ownFieldsByLevel[$level], $values);
            $row[SchemaBuilder::ID_COLUMN] = $id;
            $this->connection->insert($this->table($level), $row);
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

        $entity = $this->entityManager->instantiate($this->class, $values);
        $this->identityMap->put($this->class, $id, $entity);

        return $entity;
    }

    /** Deleting the entities row cascades through every derived level, and every Owned collection, automatically. */
    public function delete(string $id): void
    {
        $this->connection->delete(SchemaBuilder::ENTITIES_TABLE, [SchemaBuilder::ID_COLUMN => $id]);
        $this->identityMap->forget($this->class, $id);
    }

    private function table(string $class): string
    {
        return $this->tables[$class] ?? throw new LogicException(sprintf('No table registered for %s.', $class));
    }

    /**
     * Per-field FieldValidator checks first, then whole-entity PrototypeValidator
     * cross-field checks against the same fully-resolved candidate state, "is this one
     * value well-formed" is a more basic question than "is this combination of values
     * coherent," fail on the cheaper check first.
     *
     * @param array<string, mixed> $values
     */
    private function validate(array $values): void
    {
        foreach ($this->fields as $field) {
            foreach ($field->validators as $validator) {
                if (!$validator->validate($values[$field->name] ?? null)) {
                    throw new ValidationException(sprintf('Field "%s" is invalid.', $field->name));
                }
            }
        }

        foreach ($this->prototypeValidators as $validator) {
            if (!$validator->validate($values)) {
                throw new ValidationException(sprintf('%s failed a %s.', $this->class, $validator::class));
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

        return $this->entityManager->repository($field->referencedShape)->lazyFind((string) $id);
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

    /**
     * @return object[] Shared items are lazy, only the id list is fetched eagerly
     *   (unavoidable, that's what "which items are in the collection" means), each
     *   item's own hydration waits until it's actually touched. Owned items have no
     *   such cost to defer, their own data already comes back in this one query.
     */
    private function readCollection(FieldDescriptor $field, string $ownerId): array
    {
        $table = $this->collectionTable($field);

        if ($field->ownership === Ownership::Shared) {
            $itemIds = $this->connection->fetchFirstColumn(sprintf('SELECT item_id FROM %s WHERE owner_id = ?', $table), [$ownerId]);
            $itemRepository = $this->entityManager->repository($field->referencedShape);

            return array_map(static fn (int|string $itemId): object => $itemRepository->lazyFind((string) $itemId), $itemIds);
        }

        // Owned: no independent repository, hydrate the child rows directly instead
        $rows = $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE owner_id = ?', $table), [$ownerId]);
        $itemFields = $this->entityManager->fieldsOf($field->referencedShape);

        return array_map(
            fn (array $row): object => $this->entityManager->instantiate($field->referencedShape, RowMapper::fromRow($itemFields, $row)),
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

        $itemFields = $this->entityManager->fieldsOf($field->referencedShape);
        foreach ($items as $item) {
            $row = RowMapper::toRow($itemFields, RowMapper::propertiesOf($item, $itemFields));
            $row['owner_id'] = $ownerId;
            $this->connection->insert($table, $row);
        }
    }
}
