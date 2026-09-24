<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** A native class with no #[EditorExtensible] at all, admins can never subclass it. */
final readonly class SealedProduct
{
    public function __construct(
        #[Field(queryable: true)]
        public string $sku,
    ) {
    }
}
