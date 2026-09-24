<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

use Attribute;
use XyloIsCoding\CoconutCms\Storage\Permission\SchemaPermission;

/**
 * Marks a native class as subclassable by admins through the editor, Unreal's
 * Blueprintable flag. Not every native class should be extensible by default, an
 * internal infrastructure class was never meant to be content.
 *
 * Specifically about extensibility, not visibility, whether a prototype shows up as
 * its own manageable content type in the admin UI is a distinct, unbuilt concern.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EditorExtensible
{
    public function __construct(
        public ?SchemaPermission $permission = null,
    ) {
    }
}
