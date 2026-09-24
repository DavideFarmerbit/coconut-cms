<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Permission;

use XyloIsCoding\CoconutCms\Storage\Field\Embed;
use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Permission\RolePermission;

/** An entity with a top-level restricted field and an embedded value object with its own restricted field. */
final readonly class Employee
{
    public function __construct(
        #[Field(queryable: true)]
        public string $name,
        #[Field(permission: new RolePermission('hr'))]
        public string $ssn,
        #[Field]
        #[Embed]
        public Salary $salary,
    ) {
    }
}
