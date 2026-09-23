<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\RowMapper;
use XyloIsCoding\CoconutCms\Storage\ValidationException;

/**
 * Shows what one entity in a draft Changeset would look like if it were published,
 * without writing anything: no flush, no transaction, no topological sort, ordering
 * only matters across entities' relative *writes*, not for previewing a single one.
 *
 * A TempId reference to another change in the same draft resolves to that change's own
 * in-progress preview, not a database row, since it hasn't been flushed yet either.
 */
final readonly class DraftPreview
{
    public function __construct(
        private EntityManager $entityManager,
    ) {
    }

    public function preview(Changeset $changeset, string|TempId $target): object
    {
        $change = self::findChange($changeset, $target);
        $fields = PrototypeShape::ofClass($change->prototypeClass);

        $current = $change->kind === EntityChangeKind::Create ? [] : RowMapper::propertiesOf($this->currentEntity($change), $fields);
        $merged = [...$current, ...$change->values];
        $resolved = $this->resolveTempIdsWithinDraft($changeset, $merged);
        $hydrated = $this->entityManager->hydrateReferences($fields, $resolved);

        return (new ReflectionClass($change->prototypeClass))->newInstanceArgs($hydrated);
    }

    private function currentEntity(EntityChange $change): object
    {
        $id = is_string($change->target) ? $change->target : throw new LogicException('Update/Delete must target a real id, never a TempId.');

        return $this->entityManager->repository($change->prototypeClass)->find($id)
            ?? throw new ValidationException(sprintf('Cannot preview %s #%s, it does not exist.', $change->prototypeClass, $id));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function resolveTempIdsWithinDraft(Changeset $changeset, array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value instanceof TempId) {
                $values[$key] = $this->preview($changeset, $value);
            } elseif (is_array($value)) {
                $values[$key] = $this->resolveTempIdsWithinDraft($changeset, $value);
            }
        }

        return $values;
    }

    private static function findChange(Changeset $changeset, string|TempId $target): EntityChange
    {
        foreach ($changeset->changes as $change) {
            if (self::sameTarget($change->target, $target)) {
                return $change;
            }
        }

        throw new LogicException(sprintf('No change in this draft targets "%s".', is_string($target) ? $target : $target->id));
    }

    private static function sameTarget(string|TempId $a, string|TempId $b): bool
    {
        if ($a instanceof TempId || $b instanceof TempId) {
            return $a instanceof TempId && $b instanceof TempId && $a->id === $b->id;
        }

        return $a === $b;
    }
}
