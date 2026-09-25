<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

use Doctrine\DBAL\Connection;
use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Permission\Actor;
use XyloIsCoding\CoconutCms\Storage\Permission\FieldPermissionDenied;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\Repository;
use XyloIsCoding\CoconutCms\Storage\RowMapper;
use XyloIsCoding\CoconutCms\Storage\ValidationException;

/**
 * Applies a Changeset atomically: topologically sorts it (including deriving
 * delete-vs-delete order from live reference data, since a Delete's empty $values has
 * nothing to scan the way Create/Update dependencies do), checks expectedOperationId
 * conflicts and FieldPermission before touching anything, applies each change through
 * the target's own Repository, and logs the result as one ChangesetOperation.
 *
 * The whole flush runs in one database transaction, a conflict discovered partway
 * through rolls back everything already applied in this same flush, not just the one
 * change that failed.
 *
 * $actor is optional, null skips field-permission checking entirely, the same
 * "bulk/programmatic path accepts the small risk" precedent already established for
 * expectedOperationId, only the interactive editor path should always supply one.
 */
final readonly class ChangesetFlusher
{
    public function __construct(
        private Connection $connection,
        private EntityManager $entityManager,
        private UndoLog $undoLog,
    ) {
    }

    public function flush(Changeset $changeset, ?Actor $actor = null): FlushResult
    {
        $sorted = ChangesetSorter::sort($changeset->changes, $this->deleteMustPrecede(...));

        return $this->connection->transactional(function () use ($sorted, $actor): FlushResult {
            $tempIdToRealId = [];
            $records = [];

            foreach ($sorted as $change) {
                $records[] = $this->apply($change, $tempIdToRealId, $actor);
            }

            $operation = $this->undoLog->record($records);

            return new FlushResult($operation->id, $tempIdToRealId);
        });
    }

    /** @param array<string, string> $tempIdToRealId */
    private function apply(EntityChange $change, array &$tempIdToRealId, ?Actor $actor): EntityChangeRecord
    {
        $repository = $this->entityManager->repository($change->prototypeClass);
        $fields = PrototypeShape::ofClass($change->prototypeClass);

        // Before validation, an entity's own value-well-formedness is a lesser gate
        // than "is this actor even allowed to touch this field" at all. Delete's
        // $values is always empty, so this is a no-op for it, whole-entity delete
        // authorization is a content-type-level concern, not a field-level one.
        $this->assertCanWriteFields($fields, array_keys($change->values), $actor);

        return match ($change->kind) {
            EntityChangeKind::Create => $this->applyCreate($change, $repository, $fields, $tempIdToRealId),
            EntityChangeKind::Update => $this->applyUpdate($change, $repository, $fields, $tempIdToRealId),
            EntityChangeKind::Delete => $this->applyDelete($change, $repository, $fields),
        };
    }

    /**
     * @param \XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor[] $fields
     * @param array<string, string> $tempIdToRealId
     */
    private function applyCreate(EntityChange $change, Repository $repository, array $fields, array &$tempIdToRealId): EntityChangeRecord
    {
        $resolved = $this->resolveTempIds($change->values, $tempIdToRealId);
        $hydrated = $this->entityManager->hydrateReferences($fields, $resolved);

        $entity = (new ReflectionClass($change->prototypeClass))->newInstanceArgs($hydrated);
        $explicitId = is_string($change->target) ? $change->target : null;
        $id = $repository->insert($entity, $explicitId);

        if ($change->target instanceof TempId) {
            $tempIdToRealId[$change->target->id] = $id;
        }

        return new EntityChangeRecord(EntityChangeKind::Create, $id, $change->prototypeClass, [], $hydrated);
    }

    /**
     * @param \XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor[] $fields
     * @param array<string, string> $tempIdToRealId
     */
    private function applyUpdate(EntityChange $change, Repository $repository, array $fields, array $tempIdToRealId): EntityChangeRecord
    {
        $id = self::realId($change);
        $this->assertNoConflict($change, $id, array_keys($change->values));

        $current = $repository->find($id) ?? throw new ValidationException(sprintf('Cannot update %s #%s, it does not exist.', $change->prototypeClass, $id));
        $before = self::onlyKeys(RowMapper::propertiesOf($current, $fields), array_keys($change->values));

        $resolved = $this->resolveTempIds($change->values, $tempIdToRealId);
        $hydrated = $this->entityManager->hydrateReferences($fields, $resolved);

        $repository->update($id, $hydrated);

        return new EntityChangeRecord(EntityChangeKind::Update, $id, $change->prototypeClass, $before, $hydrated);
    }

    /** @param \XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor[] $fields */
    private function applyDelete(EntityChange $change, Repository $repository, array $fields): EntityChangeRecord
    {
        $id = self::realId($change);
        $this->assertNoConflict($change, $id, null);

        $current = $repository->find($id) ?? throw new ValidationException(sprintf('Cannot delete %s #%s, it does not exist.', $change->prototypeClass, $id));
        $before = RowMapper::propertiesOf($current, $fields);

        $repository->delete($id);

        return new EntityChangeRecord(EntityChangeKind::Delete, $id, $change->prototypeClass, $before, []);
    }

    /**
     * @param \XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor[] $fields
     * @param string[] $changedFieldNames
     */
    private function assertCanWriteFields(array $fields, array $changedFieldNames, ?Actor $actor): void
    {
        if ($actor === null || $changedFieldNames === []) {
            return;
        }

        $byName = [];
        foreach ($fields as $field) {
            $byName[$field->name] = $field;
        }

        foreach ($changedFieldNames as $name) {
            $permission = $byName[$name]?->permission;
            if ($permission !== null && !$permission->canWrite($actor)) {
                throw new FieldPermissionDenied(sprintf('Not allowed to write field "%s".', $name));
            }
        }
    }

    private static function realId(EntityChange $change): string
    {
        return is_string($change->target) ? $change->target : throw new LogicException('Update/Delete must target a real id, never a TempId.');
    }

    /**
     * Whether $a's entity currently holds a live scalar reference to $b's entity, so
     * $a must be deleted first, respecting FK RESTRICT instead of relying on the
     * database to reject-and-rollback the wrong order. The live-data counterpart to
     * the TempId scanning Create/Update dependencies use, needed here since a
     * Delete's $values is always empty, there's nothing to scan.
     */
    private function deleteMustPrecede(EntityChange $a, EntityChange $b): bool
    {
        if ($a->kind !== EntityChangeKind::Delete || $b->kind !== EntityChangeKind::Delete) {
            return false;
        }

        $current = $this->entityManager->repository($a->prototypeClass)->find(self::realId($a));
        if ($current === null) {
            return false;
        }

        $targetId = self::realId($b);
        foreach (PrototypeShape::ofClass($a->prototypeClass) as $field) {
            if ($field->kind !== FieldKind::EntityReference || $field->referencedShape !== $b->prototypeClass) {
                continue;
            }

            $value = RowMapper::propertiesOf($current, [$field])[$field->name] ?? null;
            if ($value !== null && $this->entityManager->idOf($value) === $targetId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject-by-default: if anything logged after expectedOperationId already touched
     * this entity (any of $fieldNames, or at all when $fieldNames is null, the Delete
     * case), the whole flush is rejected before any of it is applied.
     *
     * @param string[]|null $fieldNames null means any touch at all counts
     */
    private function assertNoConflict(EntityChange $change, string $entityId, ?array $fieldNames): void
    {
        if ($change->expectedOperationId === null) {
            return;
        }

        foreach ($this->undoLog->changesSince($change->expectedOperationId) as $record) {
            if ($record->prototypeClass !== $change->prototypeClass || $record->entityId !== $entityId) {
                continue;
            }

            if ($fieldNames === null || array_intersect($fieldNames, $record->touchedFields()) !== []) {
                throw new ConcurrentWriteException(sprintf(
                    '%s #%s was modified after operation "%s", based on a stale version.',
                    $change->prototypeClass,
                    $entityId,
                    $change->expectedOperationId,
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $tempIdToRealId
     * @return array<string, mixed>
     */
    private function resolveTempIds(array $values, array $tempIdToRealId): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof TempId) {
                $values[$key] = $tempIdToRealId[$value->id] ?? throw new LogicException(sprintf('TempId "%s" was not resolved yet.', $value->id));
            } elseif (is_array($value)) {
                $values[$key] = $this->resolveTempIds($value, $tempIdToRealId);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @param string[] $keys
     * @return array<string, mixed>
     */
    private static function onlyKeys(array $values, array $keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }
}
