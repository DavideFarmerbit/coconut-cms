<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * Marks a native property as a Scalar field: a built-in primitive value.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Scalar
{
    /**
     * @param ScalarType $type Which primitive the field holds.
     * @param string|null $enumClass The backed enum class the field holds. Required
     *        when $type is ScalarType::Enum, and invalid for every other $type.
     */
    public function __construct(
        public ScalarType $type,
        public ?string $enumClass = null,
    ) {
    }
}
