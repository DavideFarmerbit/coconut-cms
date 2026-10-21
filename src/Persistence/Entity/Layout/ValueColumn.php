<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * One column holding one stored value: a Scalar, a ValueObject or a
 * Reference's id, possibly reached through Embeds.
 */
final readonly class ValueColumn
{
    /**
     * @param FieldPath|null $path The value's path from the row's own class, or
     *        from a Collection item. Null for a Collection's scalar item itself.
     * @param FieldDescriptor $field The field holding the value, which decides
     *        the column's type.
     */
    public function __construct(
        public ?FieldPath $path,
        public string $name,
        public FieldDescriptor $field,
    ) {
    }
}
