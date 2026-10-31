<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

/**
 * An attribute naming the identifier a class is stored under: #[ValueObjectType],
 * #[EnumType], #[FieldRule] or #[PrototypeRule]. A stored schema names the class
 * by this, never by the class itself. Found code-side and module-side alike, so
 * a class has one identifier however it gets registered.
 */
interface NamesStoredClass
{
    /** The identifier, as "namespace:key". */
    public string $identifier { get; }
}
