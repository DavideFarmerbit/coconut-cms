<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

use Attribute;

/**
 * Pairs with Field on a class-typed property to mark it as a reference to another
 * entity, Shared or Owned. Use Embed instead for a property with no identity of its
 * own.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Reference
{
    public function __construct(
        public Ownership $ownership,
    ) {
    }
}
