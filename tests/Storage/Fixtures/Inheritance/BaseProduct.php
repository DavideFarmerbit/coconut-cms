<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** The base level of a Class Table Inheritance chain. Not final: it's meant to be extended. */
readonly class BaseProduct
{
    public function __construct(
        #[Field(queryable: true, unique: true)]
        public string $sku,
        #[Field(queryable: true)]
        public string $name,
    ) {
    }
}
