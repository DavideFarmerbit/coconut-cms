<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism;

use XyloIsCoding\CoconutCms\Storage\Attributes\Field;

/** A second concrete subtype of GroceryItem, own level of the chain, so a page can mix more than one foreign subtype. */
final readonly class Fruit extends GroceryItem
{
    public function __construct(
        string $name,
        #[Field(queryable: true)]
        public bool $seedless,
    ) {
        parent::__construct($name);
    }
}
