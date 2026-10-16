<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as an Embed field: an Entity-capable shape flattened into
 * this entity's own row as real columns.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Embed
{
    /**
     * @param string $type The embedded shape's identifier.
     */
    public function __construct(
        public string $type,
    ) {
    }
}
