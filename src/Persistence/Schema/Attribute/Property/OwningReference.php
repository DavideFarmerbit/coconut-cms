<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as an OwningReference field: an Owned, singular pointer to
 * another entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class OwningReference
{
    /**
     * @param string $type The owned shape's identifier.
     */
    public function __construct(
        public string $type,
    ) {
    }
}
