<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Attribute;

/**
 * Pairs with Field on a class-typed property to mark it as an embedded value object:
 * no identity of its own, always inlined into the owner's own storage.
 *
 * Use Reference instead for a property that points to another entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Embed
{
}
