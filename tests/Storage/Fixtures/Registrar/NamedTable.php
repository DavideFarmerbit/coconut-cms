<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar;

use XyloIsCoding\CoconutCms\Storage\Attributes\Field;
use XyloIsCoding\CoconutCms\Storage\Attributes\TableName;

/** Declares an explicit table name, overriding what its short class name would derive. */
#[TableName('custom_products')]
final readonly class NamedTable
{
    public function __construct(
        #[Field(queryable: true)]
        public string $name,
    ) {
    }
}
