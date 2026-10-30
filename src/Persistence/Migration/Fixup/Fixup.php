<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * One declared change to stored content, reviewed with the commit that needs
 * it, or given by an editor save. Transforms the stored schemas towards the
 * target's (see SchemaState), so whatever it explains no longer shows up as
 * a difference. Names things as they are when
 * it runs.
 */
interface Fixup
{
    /** $schemas with this change applied. Throws InvalidFixup when what it names doesn't exist at this point. */
    public function applyTo(SchemaState $schemas): SchemaState;

    /** How a blocked migration or a failed step names it: "RenameField(default:car, price → cost)". */
    public function describe(): string;
}
