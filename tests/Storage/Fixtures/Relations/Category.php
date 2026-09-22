<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** A Shared reference target: independently referenceable from more than one Product. */
final readonly class Category
{
    public function __construct(
        #[Field(queryable: true, unique: true)]
        public string $name,
    ) {
    }
}
