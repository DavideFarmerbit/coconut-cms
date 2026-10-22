<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Id;

/**
 * Finds a native class's #[Id] property, if it declares or inherits one, and
 * fills it. Checks the property's shape once per class: the reader calls it
 * so a wrong one fails at compile time, the repository so it can fill it.
 */
final class IdProperty
{
    /** @var array<class-string, ReflectionProperty|null> */
    private static array $byClass = [];

    /**
     * $class's #[Id] property, or null when its chain declares none.
     * @param class-string $class
     */
    public static function of(string $class): ?ReflectionProperty {
        if (array_key_exists($class, self::$byClass)) {
            return self::$byClass[$class];
        }

        $found = null;
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $reflection->getName() || $property->getAttributes(Id::class) === []) {
                    continue;
                }

                if ($found !== null) {
                    throw new InvalidArgumentException(sprintf(
                        '%s has two #[Id] properties: %s::$%s and %s::$%s.',
                        $class,
                        $found->getDeclaringClass()->getName(),
                        $found->getName(),
                        $reflection->getName(),
                        $property->getName(),
                    ));
                }

                self::assertShape($property);
                $found = $property;
            }
        }

        return self::$byClass[$class] = $found;
    }

    /** Sets $entity's #[Id] property to $id, if its class has one: null once it's deleted. */
    public static function assign(object $entity, ?string $id): void {
        self::of($entity::class)?->setValue($entity, $id);
    }

    /** A nullable string defaulting to null, never static, never a field. */
    private static function assertShape(ReflectionProperty $property): void {
        $type = $property->getType();
        $isNullableString = $type instanceof ReflectionNamedType && $type->getName() === 'string' && $type->allowsNull();

        $problem = match (true) {
            $property->isStatic() => 'is static',
            !$isNullableString => 'is not typed ?string',
            !$property->hasDefaultValue() || $property->getDefaultValue() !== null => 'does not default to null',
            NativeFields::kindAttributesOf($property) !== [] => 'is also a field',
            default => null,
        };

        if ($problem !== null) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is marked #[Id] but %s. It must be a nullable string defaulting to null, and never a field.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $problem,
            ));
        }
    }
}
