<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism;

use XyloIsCoding\CoconutCms\Storage\Attributes\Field;

/** A concrete subtype of GroceryItem, own level of the chain. */
final readonly class Vegetable extends GroceryItem
{
    public function __construct(
        string $name,
        #[Field(queryable: true)]
        public string $color,
    ) {
        parent::__construct($name);
    }
}
