<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations;

use XyloIsCoding\CoconutCms\Storage\Field\Collection;
use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Field\Ownership;
use XyloIsCoding\CoconutCms\Storage\Field\Reference;

/** An entity with one of each relationship kind: a Shared reference, a Shared collection, and an Owned collection. */
final readonly class RelatedProduct
{
    public function __construct(
        #[Field(queryable: true, unique: true)]
        public string $sku,
        #[Field]
        #[Reference(ownership: Ownership::Shared)]
        public Category $category,
        /** @var Tag[] */
        #[Field]
        #[Collection(itemKind: FieldKind::EntityReference, of: Tag::class, ownership: Ownership::Shared)]
        public array $tags,
        /** @var GalleryItem[] */
        #[Field]
        #[Collection(itemKind: FieldKind::EntityReference, of: GalleryItem::class, ownership: Ownership::Owned)]
        public array $gallery,
    ) {
    }
}
