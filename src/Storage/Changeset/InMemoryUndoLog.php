<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

use LogicException;

/**
 * The default UndoLog, scoped to one request/process: keeps operations in an array,
 * in insertion order. A durable, cross-request implementation (a real database table)
 * is a natural later swap, same interface-plus-swappable-default pattern used
 * throughout this codebase.
 *
 * Retention is count-based per entity, keep the last N records for a given
 * (prototypeClass, entityId) pair, not "records from the last N days", a heavily
 * edited entity shouldn't lose history to a time window, and a rarely-touched one
 * shouldn't lose its last meaningful edit after N idle days.
 */
final class InMemoryUndoLog implements UndoLog
{
    /** @var ChangesetOperation[] */
    private array $operations = [];

    private int $nextId = 1;

    public function __construct(
        private readonly int $retainPerEntity = 50,
    ) {
    }

    public function record(array $records): ChangesetOperation
    {
        $operation = new ChangesetOperation((string) $this->nextId++, $records);
        $this->operations[] = $operation;

        foreach ($records as $record) {
            $this->pruneEntity($record->prototypeClass, $record->entityId);
        }

        return $operation;
    }

    public function changesSince(?string $operationId): array
    {
        $afterIndex = $operationId === null ? -1 : $this->indexOf($operationId);

        $records = [];
        foreach ($this->operations as $index => $operation) {
            if ($index > $afterIndex) {
                array_push($records, ...$operation->changes);
            }
        }

        return $records;
    }

    public function get(string $operationId): ChangesetOperation
    {
        return $this->operations[$this->indexOf($operationId)];
    }

    private function indexOf(string $operationId): int
    {
        foreach ($this->operations as $index => $operation) {
            if ($operation->id === $operationId) {
                return $index;
            }
        }

        throw new LogicException(sprintf('Unknown operation id "%s".', $operationId));
    }

    /** Drops the oldest excess records for one entity once it has more than the retained count, whole operations once they're left empty. */
    private function pruneEntity(string $prototypeClass, string $entityId): void
    {
        $matches = [];
        foreach ($this->operations as $opIndex => $operation) {
            foreach ($operation->changes as $recordIndex => $record) {
                if ($record->prototypeClass === $prototypeClass && $record->entityId === $entityId) {
                    $matches[] = [$opIndex, $recordIndex];
                }
            }
        }

        $excess = count($matches) - $this->retainPerEntity;
        if ($excess <= 0) {
            return;
        }

        $toRemoveByOperation = [];
        foreach (array_slice($matches, 0, $excess) as [$opIndex, $recordIndex]) {
            $toRemoveByOperation[$opIndex][] = $recordIndex;
        }

        foreach ($toRemoveByOperation as $opIndex => $recordIndices) {
            $operation = $this->operations[$opIndex];
            $remaining = array_values(array_diff_key($operation->changes, array_flip($recordIndices)));

            $this->operations[$opIndex] = $remaining === [] ? null : new ChangesetOperation($operation->id, $remaining);
        }

        $this->operations = array_values(array_filter($this->operations, static fn (?ChangesetOperation $op): bool => $op !== null));
    }
}
