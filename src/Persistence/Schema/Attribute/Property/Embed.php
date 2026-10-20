<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as an Embed field: an Entity-capable shape flattened into
 * this entity's own row as real columns. The target is the property's own declared
 * type, which must be a single #[Entity]-marked class.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Embed
{
}
