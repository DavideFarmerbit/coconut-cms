<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;

/**
 * One entry a migration moves, as a preview shows it: what changes in it,
 * flat and in stored form, owned objects included. `before` holds every path
 * whose value changes or goes, under its old name; `after` every path whose
 * value changes or comes, under its new name. A rename shows the old path
 * before and the new one after. A value following a default, before and
 * after, isn't shown: it isn't the entry's own.
 */
final readonly class EntrySample
{
    /**
     * @param string $id The entry's id.
     * @param string $type Its type once moved.
     * @param array<string, mixed> $before Keyed by path.
     * @param array<string, mixed> $after Keyed by path.
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $before,
        public array $after,
    ) {
    }

    /**
     * What changes in entry $id, from its values and marks before the move to
     * those after; null when nothing of its own does.
     * @param array<string, mixed> $beforeValues
     * @param array<string, ValueMark> $beforeMarks
     * @param array<string, mixed> $afterValues
     * @param array<string, ValueMark> $afterMarks
     */
    public static function of(string $id, string $type, array $beforeValues, array $beforeMarks, array $afterValues, array $afterMarks): ?self {
        $before = self::own($beforeValues, $beforeMarks);
        $after = self::own($afterValues, $afterMarks);

        $changedBefore = [];
        foreach ($before as $path => $value) {
            if (!array_key_exists($path, $after) || $after[$path] !== $value) {
                $changedBefore[$path] = $value;
            }
        }

        $changedAfter = [];
        foreach ($after as $path => $value) {
            if (!array_key_exists($path, $before) || $before[$path] !== $value) {
                $changedAfter[$path] = $value;
            }
        }

        return $changedBefore === [] && $changedAfter === [] ? null : new self($id, $type, $changedBefore, $changedAfter);
    }

    /**
     * The values of $values the entry holds itself: none following a default.
     * @param array<string, mixed> $values
     * @param array<string, ValueMark> $marks
     * @return array<string, mixed>
     */
    private static function own(array $values, array $marks): array {
        return array_filter($values, static fn (string $path): bool => !($marks[$path] ?? ValueMark::Touched)->isDefault(), ARRAY_FILTER_USE_KEY);
    }
}
