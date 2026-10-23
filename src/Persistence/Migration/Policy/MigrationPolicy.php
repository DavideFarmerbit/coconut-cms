<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Policy;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * What happens to a difference no declared fixup explains. Boot code passes a
 * policy as an object; strict applies when it passes none.
 */
interface MigrationPolicy
{
    /**
     * The fixup that resolves $difference, or null to block the migration.
     * @param SchemaState $schemas The stored schemas after every declared fixup.
     */
    public function fixupFor(Difference $difference, SchemaState $schemas): ?Fixup;
}
