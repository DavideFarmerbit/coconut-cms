<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

/**
 * An attribute naming the identifier a class is stored under: #[Entity],
 * #[ValueObjectType], #[EnumType], #[FieldRule] or #[PrototypeRule]. Stored
 * content names the class by this, never by the class itself. Found code-side
 * and module-side alike, so a class has one identifier however it gets
 * registered.
 */
interface NamesStoredClass
{
    /**
     * The identifier, as "namespace:key". Null only on #[Entity], which marks
     * a class as an entity whether it names one or not: the class is then
     * stored under "default:" and its short name, snake-cased.
     */
    public ?string $identifier { get; }
}
