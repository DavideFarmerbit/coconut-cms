<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks the native property the repository fills with the entity's id, on save
 * and on load. Optional: only a class that needs its id in hand declares one.
 * Never a field and never a default: it must be a nullable string defaulting to
 * null, and stays null on an Embed or an entity never saved. Only the
 * repository assigns it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Id
{
}
