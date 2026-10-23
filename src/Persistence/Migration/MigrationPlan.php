<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MovesValues;

/**
 * Every fixup a migration runs, in order, once it's known that together they
 * explain every difference: the pending steps' declared fixups, then the ones
 * the policy chose. What the data side moves content through.
 */
final readonly class MigrationPlan
{
    /**
     * @param list<string> $stepIds The pending steps, in the order they run:
     *        what the database records as applied once the migration commits.
     * @param list<PlannedFixup> $fixups Declared fixups first, then the policy's.
     * @param SchemaState $schemas The stored schemas once every fixup ran:
     *        the code's, plus every editor-created schema as fixed.
     */
    public function __construct(
        public array $stepIds,
        public array $fixups,
        public SchemaState $schemas,
    ) {
    }

    /** Runs every fixup's data side over $values, in order. */
    public function moveValues(AggregateValues $values): void {
        foreach ($this->fixups as $planned) {
            if ($planned->fixup instanceof MovesValues) {
                $planned->fixup->moveValues($values, $planned->before, $planned->after);
            }
        }
    }

    /** Whether the migration has anything to do. */
    public function isEmpty(): bool {
        return $this->stepIds === [] && $this->fixups === [];
    }
}
