<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * Marks a native property as a Scalar field: a built-in primitive value. The
 * property must be declared as what $type holds; for ScalarType::Enum, the
 * enum class is the property's own declared type.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Scalar
{
    /** @param ScalarType $type Which primitive the field holds. */
    public function __construct(
        public ScalarType $type,
    ) {
    }
}
