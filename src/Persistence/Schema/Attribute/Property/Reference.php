<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as a Reference field: a Shared, singular pointer to another
 * entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Reference
{
    /**
     * @param string $type The referenced shape's identifier.
     */
    public function __construct(
        public string $type,
    ) {
    }
}
