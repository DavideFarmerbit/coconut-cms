<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

use Attribute;

/**
 * Marks an array-typed property as a repeated list of values. PHP's own reflection
 * can't tell what an array holds, so this attribute carries it explicitly: the kind of
 * each item, and for EntityReference items, which entity and Shared vs Owned.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Collection
{
    /** @param class-string|null $of the item class, required for EntityReference or EmbeddedValueObject items */
    public function __construct(
        public FieldKind $itemKind,
        public ?string $of = null,
        public ?Ownership $ownership = null,
    ) {
    }
}
