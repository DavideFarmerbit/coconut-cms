<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;

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
     * @param list<Fixup> $fixups Declared fixups first, then the policy's.
     */
    public function __construct(
        public array $stepIds,
        public array $fixups,
    ) {
    }

    /** Whether the migration has anything to do. */
    public function isEmpty(): bool {
        return $this->stepIds === [] && $this->fixups === [];
    }
}
