<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as a ValueObject field: a custom primitive type, converted
 * to and from the database by its own Type class.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ValueObject
{
    /**
     * @param string $type The custom Type class converting this field's value to and
     *        from the database.
     */
    public function __construct(
        public string $type,
    ) {
    }
}
