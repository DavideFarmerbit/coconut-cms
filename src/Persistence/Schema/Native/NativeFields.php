<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;

/**
 * Which properties of a native class are fields, by reflection: shared by
 * everything reading a native class or object, so they all agree.
 */
final class NativeFields
{
    private const KIND_ATTRIBUTES = [Scalar::class, ValueObject::class, Embed::class, Reference::class, OwningReference::class, Collection::class];

    /** Whether $class is marked #[Entity]. */
    public static function isEntity(string $class): bool {
        return (new ReflectionClass($class))->getAttributes(Entity::class) !== [];
    }

    /**
     * $property's field-kind attributes: one for a field, none for any other
     * property.
     * @return list<ReflectionAttribute<object>>
     */
    public static function kindAttributesOf(ReflectionProperty $property): array {
        $attributes = [];
        foreach (self::KIND_ATTRIBUTES as $kind) {
            array_push($attributes, ...$property->getAttributes($kind));
        }

        return $attributes;
    }

    /**
     * Every field $reflection declares or inherits, through any number of
     * #[Entity]-marked or plain ancestors alike: a field is a property of an
     * #[Entity]-marked class carrying a field-kind attribute. Always climbs the
     * full PHP ancestor chain: a non-#[Entity] class contributes no field of its
     * own, but doesn't stop the walk from reaching a real entity past it.
     * Reflects each class in the chain separately rather than calling
     * getProperties() once on the most-derived class: that single call silently
     * drops a private property declared by an ancestor.
     * @param ReflectionClass<object> $reflection
     * @return list<ReflectionProperty>
     */
    public static function of(ReflectionClass $reflection): array {
        $parent = $reflection->getParentClass();
        $fields = $parent !== false ? self::of($parent) : [];

        if (!self::isEntity($reflection->getName())) {
            return $fields;
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $reflection->getName() && self::kindAttributesOf($property) !== []) {
                $fields[] = $property;
            }
        }

        return $fields;
    }
}
