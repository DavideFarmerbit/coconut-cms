<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Permission;

use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Permission\RolePermission;

/** An entity with one ordinary field and one field only an "editor" role can read/write. */
final readonly class RestrictedNote
{
    public function __construct(
        #[Field(queryable: true)]
        public string $title,
        #[Field(permission: new RolePermission('editor'))]
        public string $body,
    ) {
    }
}
