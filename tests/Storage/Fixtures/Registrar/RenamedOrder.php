<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** Stands in for "the class after a rename", used against a table built under some other, pre-rename name. */
final readonly class RenamedOrder
{
    public function __construct(
        #[Field(queryable: true)]
        public string $reference,
    ) {
    }
}
