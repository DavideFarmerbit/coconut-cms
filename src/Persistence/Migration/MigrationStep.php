<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;

/**
 * One reviewed set of fixups. Boot code lists every step in the order they
 * run; the database records the id of each one it has applied. A step can be
 * deleted from the code once every database has applied it.
 */
interface MigrationStep
{
    /** Unique among every step, and never reused: it's what the database records. */
    public function id(): string;

    /** @return list<Fixup> In the order they run. */
    public function fixups(): array;
}
