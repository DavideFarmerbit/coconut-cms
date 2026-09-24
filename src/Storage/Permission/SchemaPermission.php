<?php

namespace XyloIsCoding\CoconutCms\Storage\Permission;

/**
 * Gates who can trigger a shape change on a prototype: create a subclass of an
 * EditorExtensible class, or add/drop a column on one. Attach to EditorExtensible's
 * `permission` parameter, `null` meaning no restriction beyond ordinary admin access.
 */
interface SchemaPermission
{
    /** Whether $actor even sees this prototype as an extension point at all. */
    public function canRead(Actor $actor): bool;

    /** Whether $actor can create a subclass, or add/drop a column on this prototype. */
    public function canWrite(Actor $actor): bool;
}
