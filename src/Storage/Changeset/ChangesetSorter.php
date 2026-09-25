<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

use LogicException;

/**
 * Orders a changeset's entries so a Create always comes before anything referencing
 * its TempId, full topological sort (Kahn's algorithm), not a hand-maintained list of
 * supported patterns. Dependency edges are derived automatically by scanning each
 * change's values for TempId instances, never manually declared.
 *
 * Delete entries have no `$values` to scan, so a caller can supply $mustPrecede to
 * derive delete-vs-delete edges from live data instead (does this Delete's entity
 * currently reference that Delete's entity), the same "reversed direction from
 * inserts" ordering the write path already promises, just sourced differently since
 * there's no changeset-shape signal to scan for it. Without one, entries with no
 * dependency simply keep their original relative order; the FK `RESTRICT` policy
 * remains the backstop either way if something still gets past this.
 */
final class ChangesetSorter
{
    /**
     * @param EntityChange[] $changes
     * @param (callable(EntityChange, EntityChange): bool)|null $mustPrecede given (a, b),
     *   true if a must be applied before b, beyond what TempId scanning already finds
     * @return EntityChange[]
     */
    public static function sort(array $changes, ?callable $mustPrecede = null): array
    {
        $tempIdOwner = self::tempIdOwners($changes);
        [$dependents, $inDegree] = self::buildGraph($changes, $tempIdOwner, $mustPrecede);

        $queue = array_keys(array_filter($inDegree, static fn (int $degree): bool => $degree === 0));
        $order = [];

        while ($queue !== []) {
            sort($queue);
            $index = array_shift($queue);
            $order[] = $index;

            foreach ($dependents[$index] as $dependent) {
                if (--$inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($order) !== count($changes)) {
            throw new LogicException(sprintf(
                'Changeset has a cycle among: %s.',
                implode(', ', self::targetLabels($changes, array_diff(array_keys($changes), $order))),
            ));
        }

        return array_map(static fn (int $index): EntityChange => $changes[$index], $order);
    }

    /**
     * @param EntityChange[] $changes
     * @return array<string, int> TempId id => the index of the Create that owns it
     */
    private static function tempIdOwners(array $changes): array
    {
        $owners = [];
        foreach ($changes as $index => $change) {
            if ($change->kind === EntityChangeKind::Create && $change->target instanceof TempId) {
                $owners[$change->target->id] = $index;
            }
        }

        return $owners;
    }

    /**
     * @param EntityChange[] $changes
     * @param array<string, int> $tempIdOwner
     * @param (callable(EntityChange, EntityChange): bool)|null $mustPrecede
     * @return array{0: array<int, int[]>, 1: array<int, int>} dependents per index, in-degree per index
     */
    private static function buildGraph(array $changes, array $tempIdOwner, ?callable $mustPrecede): array
    {
        $dependents = array_fill(0, count($changes), []);
        $inDegree = array_fill(0, count($changes), 0);

        foreach ($changes as $index => $change) {
            foreach (self::referencedTempIds($change->values) as $tempId) {
                $owner = $tempIdOwner[$tempId] ?? throw new LogicException(sprintf('Changeset references an unknown TempId "%s".', $tempId));

                if ($owner === $index) {
                    throw new LogicException(sprintf('Change targeting "%s" cannot depend on its own TempId.', $tempId));
                }

                $dependents[$owner][] = $index;
                $inDegree[$index]++;
            }
        }

        if ($mustPrecede !== null) {
            foreach ($changes as $i => $a) {
                foreach ($changes as $j => $b) {
                    if ($i !== $j && $mustPrecede($a, $b)) {
                        $dependents[$i][] = $j;
                        $inDegree[$j]++;
                    }
                }
            }
        }

        return [$dependents, $inDegree];
    }

    /**
     * @param array<string, mixed> $values
     * @return string[]
     */
    private static function referencedTempIds(array $values): array
    {
        $ids = [];
        array_walk_recursive($values, static function (mixed $value) use (&$ids): void {
            if ($value instanceof TempId) {
                $ids[] = $value->id;
            }
        });

        return $ids;
    }

    /**
     * @param EntityChange[] $changes
     * @param int[] $indices
     * @return string[]
     */
    private static function targetLabels(array $changes, array $indices): array
    {
        return array_map(
            static fn (int $index): string => $changes[$index]->target instanceof TempId ? $changes[$index]->target->id : $changes[$index]->target,
            $indices,
        );
    }
}
