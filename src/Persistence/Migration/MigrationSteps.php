<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;

/**
 * Every migration step, in the order they run: the order they're added in.
 * Boot code declares them all here; the database records which ones it has
 * applied. A step can be deleted once every database has applied it.
 * Immutable: add() returns a new list.
 */
final readonly class MigrationSteps
{
    /** @param list<MigrationStep> $steps */
    private function __construct(
        private array $steps,
    ) {
    }

    public static function none(): self {
        return new self([]);
    }

    /**
     * These steps, then one more. Throws when $id is already taken: an id is
     * what the database records, so two steps can never share one.
     * @param list<Fixup> $fixups In the order they run.
     */
    public function add(string $id, array $fixups): self {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                throw new InvalidArgumentException(sprintf('Two migration steps have the id "%s".', $id));
            }
        }

        return new self([...$this->steps, new MigrationStep($id, $fixups)]);
    }

    /**
     * Only the steps whose id isn't among $applied, still in order: what a
     * database still has to run.
     * @param list<string> $applied
     */
    public function pending(array $applied): self {
        return new self(array_values(array_filter($this->steps, static fn (MigrationStep $step): bool => !in_array($step->id, $applied, true))));
    }

    /** @return list<MigrationStep> In the order they run. */
    public function all(): array {
        return $this->steps;
    }

    /** @return list<string> Every step's id, in order. */
    public function ids(): array {
        return array_map(static fn (MigrationStep $step): string => $step->id, $this->steps);
    }
}
