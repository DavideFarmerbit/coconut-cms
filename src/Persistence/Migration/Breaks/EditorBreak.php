<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Breaks;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;

/**
 * Something a migration's schemas break on an editor-created type: a field
 * whose target is gone, a field whose name now clashes, a chain cut, a rule
 * that no longer builds. Found by the schemas alone, and decided by the
 * policy like any other difference: strict blocks, a looser one sets it
 * aside or keeps it broken.
 */
interface EditorBreak extends Difference
{
    /** What tells this break from any other: the same break found twice has the same key. */
    public function key(): string;
}
