<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * A fixup that changes values, not only schemas: it renames or moves paths,
 * converts, or fills. A fixup without it leaves values alone, and they follow
 * their paths: whatever the new schemas no longer have is lost.
 */
interface MovesValues
{
    /**
     * Changes one aggregate's values, at every place it applies.
     * @param SchemaState $before The schemas as this fixup finds them: where
     *        its field and type are.
     * @param SchemaState $after The schemas as it leaves them: what its
     *        field becomes.
     */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void;
}
