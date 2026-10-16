<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\PrototypeValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Method\DefaultInstance;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;

/**
 * Reflects a native class's attributes into an EntitySchema.
 */
final class EntityRegistrar
{
    /**
     * @param class-string $class
     */
    public static function register(string $class): EntitySchema {
        $reflection = new ReflectionClass($class);

        $entity = self::resolveEntityAttribute($reflection);
        $fields = self::resolveFields($reflection);

        return new EntitySchema(
            identifier: $class,
            parent: self::resolveParent($reflection),
            table: $entity->table,
            editorExtensible: $entity->editorExtensible,
            fields: $fields,
            fieldPermissions: self::resolveFieldPermissions($reflection),
            fieldValidators: self::resolveFieldValidators($reflection, $fields),
            uniqueGroups: self::resolveUniqueGroups($reflection, $fields),
            prototypeValidators: self::resolvePrototypeValidators($reflection, $fields),
            defaultInstanceFactory: self::resolveDefaultInstanceFactory($reflection),
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function resolveEntityAttribute(ReflectionClass $reflection): Entity {
        $attributes = $reflection->getAttributes(Entity::class);
        if ($attributes === []) {
            throw new InvalidArgumentException(sprintf('%s must be marked #[Entity].', $reflection->getName()));
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function resolveParent(ReflectionClass $reflection): ?string {
        $parent = $reflection->getParentClass();
        if ($parent === false || $parent->getAttributes(Entity::class) === []) {
            return null;
        }

        return $parent->getName();
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return FieldDescriptor[]
     */
    private static function resolveFields(ReflectionClass $reflection): array {
        $fields = [];

        foreach (self::ownProperties($reflection) as $property) {
            $fields[$property->getName()] = self::resolveFieldDescriptor($property);
        }

        return $fields;
    }

    private static function resolveFieldDescriptor(ReflectionProperty $property): FieldDescriptor {
        $name = $property->getName();

        $kindAttributes = array_merge(
            $property->getAttributes(Scalar::class),
            $property->getAttributes(ValueObject::class),
            $property->getAttributes(Embed::class),
            $property->getAttributes(Reference::class),
            $property->getAttributes(OwningReference::class),
            $property->getAttributes(Collection::class),
        );

        if (count($kindAttributes) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s must carry exactly one field-kind attribute, found %d.',
                $property->getDeclaringClass()->getName(),
                $name,
                count($kindAttributes),
            ));
        }

        $attribute = $kindAttributes[0]->newInstance();

        return match (true) {
            $attribute instanceof Scalar => FieldDescriptor::scalar($name, $attribute->type, self::resolveEnumClass($property, $attribute)),
            $attribute instanceof ValueObject => FieldDescriptor::valueObject($name, $attribute->type),
            $attribute instanceof Embed => FieldDescriptor::embed($name, $attribute->type),
            $attribute instanceof Reference => FieldDescriptor::reference($name, $attribute->type),
            $attribute instanceof OwningReference => FieldDescriptor::owningReference($name, $attribute->type),
            $attribute instanceof Collection => FieldDescriptor::collection($name, self::resolveCollectionItemDescriptor($name, $attribute)),
        };
    }

    /**
     * A singular Scalar::Enum field doesn't need $enumClass stated on the attribute;
     * it's read off the property's own declared type instead.
     */
    private static function resolveEnumClass(ReflectionProperty $property, Scalar $attribute): ?string {
        if ($attribute->type !== ScalarType::Enum) {
            return null;
        }

        if ($attribute->enumClass !== null) {
            return $attribute->enumClass;
        }

        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || !enum_exists($type->getName())) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is ScalarType::Enum but its own type is not a single enum class; pass $enumClass explicitly.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        return $type->getName();
    }

    private static function resolveCollectionItemDescriptor(string $name, Collection $attribute): FieldDescriptor {
        return match ($attribute->itemKind) {
            FieldKind::Scalar => FieldDescriptor::scalar($name, $attribute->itemScalarType, $attribute->itemEnumClass),
            FieldKind::ValueObject => FieldDescriptor::valueObject($name, $attribute->itemTarget),
            FieldKind::Embed => FieldDescriptor::embed($name, $attribute->itemTarget),
            FieldKind::Reference => FieldDescriptor::reference($name, $attribute->itemTarget),
            FieldKind::OwningReference => FieldDescriptor::owningReference($name, $attribute->itemTarget),
            FieldKind::Collection => throw new InvalidArgumentException('A Collection item cannot itself be a Collection.'),
        };
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array<string, FieldPermission>
     */
    private static function resolveFieldPermissions(ReflectionClass $reflection): array {
        $permissions = [];

        foreach (self::ownProperties($reflection) as $property) {
            $attributes = $property->getAttributes(FieldPermission::class);
            if ($attributes !== []) {
                $permissions[$property->getName()] = $attributes[0]->newInstance();
            }
        }

        return $permissions;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param FieldDescriptor[] $fields
     * @return array<string, Validation\FieldValidatorRule[]>
     */
    private static function resolveFieldValidators(ReflectionClass $reflection, array $fields): array {
        $validators = [];

        foreach (self::ownProperties($reflection) as $property) {
            $attributes = $property->getAttributes(FieldValidator::class);
            if ($attributes === []) {
                continue;
            }

            $rules = $attributes[0]->newInstance()->rules;
            foreach ($rules as $rule) {
                $rule->checkUsage($fields[$property->getName()]);
            }

            $validators[$property->getName()] = $rules;
        }

        return $validators;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param FieldDescriptor[] $fields
     * @return string[][]
     */
    private static function resolveUniqueGroups(ReflectionClass $reflection, array $fields): array {
        $groups = [];

        foreach ($reflection->getAttributes(Unique::class) as $attribute) {
            $group = $attribute->newInstance()->fields;

            foreach ($group as $name) {
                if (!array_key_exists($name, $fields)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s declares #[Unique] over "%s", which is not one of its own fields.',
                        $reflection->getName(),
                        $name,
                    ));
                }
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param FieldDescriptor[] $fields
     * @return Validation\PrototypeValidatorRule[]
     */
    private static function resolvePrototypeValidators(ReflectionClass $reflection, array $fields): array {
        $rules = [];

        foreach ($reflection->getAttributes(PrototypeValidator::class) as $attribute) {
            foreach ($attribute->newInstance()->rules as $rule) {
                $rule->checkUsage($fields);
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function resolveDefaultInstanceFactory(ReflectionClass $reflection): Closure {
        $markedMethods = array_values(array_filter(
            $reflection->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->getAttributes(DefaultInstance::class) !== [],
        ));

        if (count($markedMethods) > 1) {
            throw new InvalidArgumentException(sprintf(
                '%s marks more than one method #[DefaultInstance].',
                $reflection->getName(),
            ));
        }

        if ($markedMethods !== []) {
            $method = $markedMethods[0];
            if (!$method->isStatic() || $method->getNumberOfParameters() !== 0) {
                throw new InvalidArgumentException(sprintf(
                    '%s::%s() must be static and take no parameters to carry #[DefaultInstance].',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            return static fn (): object => $method->invoke(null);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidArgumentException(sprintf(
                '%s needs a zero-argument constructor, or a static method marked #[DefaultInstance].',
                $reflection->getName(),
            ));
        }

        return static fn (): object => $reflection->newInstance();
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return ReflectionProperty[]
     */
    private static function ownProperties(ReflectionClass $reflection): array {
        return array_values(array_filter(
            $reflection->getProperties(),
            static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $reflection->getName(),
        ));
    }
}
