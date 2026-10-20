<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as an OwningReference field: an Owned, singular pointer to
 * another entity. The target is the property's own declared type, which must be a
 * single #[Entity]-marked class; the value it holds can be any subclass of it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class OwningReference
{
}
