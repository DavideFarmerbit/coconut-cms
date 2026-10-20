<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as a Reference field: a Shared, singular pointer to another
 * entity. The target is the property's own declared type, which must be a single
 * #[Entity]-marked class.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Reference
{
}
