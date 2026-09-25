<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

use Attribute;

/**
 * Names a native class's own table explicitly, overriding the derived fallback (its
 * own short class name, lowercased). Only needed for a legacy table, a reserved SQL
 * word, a deliberately shared naming scheme, or to resolve a collision with another
 * class that derives to the same name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TableName
{
    public function __construct(
        public string $name,
    ) {
    }
}
