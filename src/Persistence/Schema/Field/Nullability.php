<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

/**
 * Whether a field, or a Collection's items, may hold null. A native field
 * takes it from its property's type; a Collection's items declare it. A
 * Reference is always Nullable: deleting its target nulls it. An Embed and a
 * Collection have none: they're never null.
 */
enum Nullability: string
{
    case Nullable = 'nullable';
    case NotNullable = 'not_nullable';

    /** Whether null is a valid value. */
    public function allowsNull(): bool {
        return $this === self::Nullable;
    }
}
