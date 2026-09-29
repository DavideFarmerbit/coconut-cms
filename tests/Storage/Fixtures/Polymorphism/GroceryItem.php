<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism;

use XyloIsCoding\CoconutCms\Storage\Attributes\Field;

/** The base level of a Class Table Inheritance chain, referenced polymorphically by Basket::item. Not final: meant to be extended. */
readonly class GroceryItem
{
    public function __construct(
        #[Field(queryable: true, unique: true)]
        public string $name,
    ) {
    }
}
