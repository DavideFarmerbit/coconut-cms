<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism;

use XyloIsCoding\CoconutCms\Storage\Attributes\Field;
use XyloIsCoding\CoconutCms\Storage\Attributes\Ownership;
use XyloIsCoding\CoconutCms\Storage\Attributes\Reference;

/** Holds a GroceryItem-declared Shared reference that a concrete subtype (Vegetable) can be assigned to. */
final readonly class Basket
{
    public function __construct(
        #[Field(queryable: true, unique: true)]
        public string $label,
        #[Field]
        #[Reference(ownership: Ownership::Shared)]
        public GroceryItem $item,
    ) {
    }
}
