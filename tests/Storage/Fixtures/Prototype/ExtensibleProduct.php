<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype;

use XyloIsCoding\CoconutCms\Storage\Field\EditorExtensible;
use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Permission\RolePermission;

/** A native class admins are allowed to subclass, gated to the store-manager role. */
#[EditorExtensible(permission: new RolePermission('store-manager'))]
readonly class ExtensibleProduct
{
    public function __construct(
        #[Field(queryable: true)]
        public string $sku,
    ) {
    }
}
