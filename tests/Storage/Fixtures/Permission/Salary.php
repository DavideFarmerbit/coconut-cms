<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Permission;

use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Permission\RolePermission;

/** An embedded value object with its own restricted field, to prove read-filtering recurses. */
final readonly class Salary
{
    public function __construct(
        #[Field]
        public int $amount,
        #[Field(permission: new RolePermission('hr'))]
        public string $notes,
    ) {
    }
}
