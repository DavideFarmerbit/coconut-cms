<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/** One value of an aggregate naming a type through a type reference, singular or as a Collection item. */
final readonly class NamedType
{
    /**
     * @param FieldPath $path Where the value is.
     * @param string $identifier The type it names.
     * @param FieldDescriptor $field The type reference: the field's own descriptor, or its Collection's item descriptor.
     * @param FieldPath|null $collection The Collection holding it, for an item; null for a singular field.
     */
    public function __construct(
        public FieldPath $path,
        public string $identifier,
        public FieldDescriptor $field,
        public ?FieldPath $collection,
    ) {
    }
}
