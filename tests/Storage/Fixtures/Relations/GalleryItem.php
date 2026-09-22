<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** An Owned collection item: a repeater row that only ever exists as part of one Product. */
final readonly class GalleryItem
{
    public function __construct(
        #[Field(queryable: true)]
        public string $caption,
    ) {
    }
}
