<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism;

use XyloIsCoding\CoconutCms\Storage\Attributes\Collection;
use XyloIsCoding\CoconutCms\Storage\Attributes\Field;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Attributes\Ownership;
use XyloIsCoding\CoconutCms\Storage\Attributes\Reference;

/**
 * Two Owned fields of the same referenced type, disambiguated only by owner_field
 * (Phase 8 Step C's "Done when" bar): a singular Owned reference and an Owned
 * collection, both of GroceryItem.
 */
final readonly class Crate
{
    public function __construct(
        #[Field(queryable: true, unique: true)]
        public string $label,
        #[Field]
        #[Reference(ownership: Ownership::Owned)]
        public GroceryItem $featured,
        /** @var GroceryItem[] */
        #[Field]
        #[Collection(itemKind: FieldKind::EntityReference, of: GroceryItem::class, ownership: Ownership::Owned)]
        public array $items,
    ) {
    }
}
