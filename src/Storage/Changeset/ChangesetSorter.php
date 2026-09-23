<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

use LogicException;

/**
 * Orders a changeset's entries so a Create always comes before anything referencing
 * its TempId, full topological sort (Kahn's algorithm), not a hand-maintained list of
 * supported patterns. Dependency edges are derived automatically by scanning each
 * change's values for TempId instances, never manually declared.
 *
 * Entries with no TempId dependency (an Update or Delete by real id, or a Create with
 * no references) simply keep their original relative order, deletes included, this
 * class doesn't try to infer delete-vs-delete ordering from live data; the FK
 * `RESTRICT` policy is the backstop if a caller gets that order wrong.
 */
final class ChangesetSorter
{
    /**
     * @param EntityChange[] $changes
     * @return EntityChange[]
     */
    public static function sort(array $changes): array
    {
        $tempIdOwner = self::tempIdOwners($changes);
        [$dependents, $inDegree] = self::buildGraph($changes, $tempIdOwner);

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
     * @return array{0: array<int, int[]>, 1: array<int, int>} dependents per index, in-degree per index
     */
    private static function buildGraph(array $changes, array $tempIdOwner): array
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
