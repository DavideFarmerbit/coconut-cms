<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Declares which role may read and write this field. Null means no restriction.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class FieldPermission
{
    /**
     * @param string|null $read Role required to read this field.
     * @param string|null $write Role required to write this field.
     */
    public function __construct(
        public ?string $read = null,
        public ?string $write = null,
    ) {
    }
}
