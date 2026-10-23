<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;

/**
 * One fixup of a plan, with the schemas it finds and leaves: what its data
 * side reads to know where its field lives and what it becomes.
 */
final readonly class PlannedFixup
{
    public function __construct(
        public Fixup $fixup,
        public SchemaState $before,
        public SchemaState $after,
    ) {
    }
}
