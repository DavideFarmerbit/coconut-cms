<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use Closure;
use InvalidArgumentException;
use ReflectionAttribute;
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
        $fieldPermissions = self::resolveFieldPermissions($reflection);
        $fieldValidators = self::resolveFieldValidators($reflection, $fields);
        $uniqueGroups = self::resolveUniqueGroups($reflection, $fields);
        $prototypeValidators = self::resolvePrototypeValidators($reflection, $fields);
        $defaultInstanceFactory = self::resolveDefaultInstanceFactory($reflection);
        $defaultInstance = $defaultInstanceFactory();

        self::assertDefaultInstanceIsInitialized($reflection, $defaultInstance);

        return new EntitySchema(
            identifier: $class,
            parent: self::resolveParent($reflection),
            table: $entity->table ?? self::deriveTableName($reflection->getShortName()),
            editorExtensible: $entity->editorExtensible,
            fields: $fields,
            fieldPermissions: $fieldPermissions,
            fieldValidators: $fieldValidators,
            uniqueGroups: $uniqueGroups,
            prototypeValidators: $prototypeValidators,
            defaultValues: self::extractDefaultValues($reflection, $defaultInstance),
            defaultInstanceFactory: $defaultInstanceFactory,
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

    /** Snake-cases a short class name: Product -> product, BlogPost -> blog_post. */
    private static function deriveTableName(string $shortClassName): string {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortClassName));
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function resolveParent(ReflectionClass $reflection): ?string {
        $parent = $reflection->getParentClass();
        if ($parent === false || !self::isEntity($parent->getName())) {
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

        $kindAttributes = self::fieldKindAttributesOf($property);

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
            $attribute instanceof Embed => FieldDescriptor::embed($name, self::resolveEntityTarget($attribute->type, $property)),
            $attribute instanceof Reference => FieldDescriptor::reference($name, self::resolveEntityTarget($attribute->type, $property)),
            $attribute instanceof OwningReference => FieldDescriptor::owningReference($name, self::resolveEntityTarget($attribute->type, $property)),
            $attribute instanceof Collection => FieldDescriptor::collection($name, self::resolveCollectionItemDescriptor($name, $attribute, $property)),
        };
    }

    /** 
     * Gets all the Field attributes specified for this property
     * @return ReflectionAttribute<object>[] 
     */
    private static function fieldKindAttributesOf(ReflectionProperty $property): array {
        return array_merge(
            $property->getAttributes(Scalar::class),
            $property->getAttributes(ValueObject::class),
            $property->getAttributes(Embed::class),
            $property->getAttributes(Reference::class),
            $property->getAttributes(OwningReference::class),
            $property->getAttributes(Collection::class),
        );
    }

    /**
     * Every Embed/Reference/OwningReference target is an ordinary #[Entity]-marked
     * class, used differently at this one field site, never a separate kind of
     * target in its own right.
     */
    private static function resolveEntityTarget(string $target, ReflectionProperty $property): string {
        if (!class_exists($target) || !self::isEntity($target)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s targets "%s", which is not a class marked #[Entity].',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $target,
            ));
        }

        return $target;
    }

    private static function isEntity(string $class): bool {
        return (new ReflectionClass($class))->getAttributes(Entity::class) !== [];
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

    private static function resolveCollectionItemDescriptor(string $name, Collection $attribute, ReflectionProperty $property): FieldDescriptor {
        return match ($attribute->itemKind) {
            FieldKind::Scalar => FieldDescriptor::scalar($name, $attribute->itemScalarType, $attribute->itemEnumClass),
            FieldKind::ValueObject => FieldDescriptor::valueObject($name, self::resolveItemTarget($attribute, $property)),
            FieldKind::Embed => FieldDescriptor::embed($name, self::resolveEntityItemTarget($attribute, $property)),
            FieldKind::Reference => FieldDescriptor::reference($name, self::resolveEntityItemTarget($attribute, $property)),
            FieldKind::OwningReference => FieldDescriptor::owningReference($name, self::resolveEntityItemTarget($attribute, $property)),
            FieldKind::Collection => throw new InvalidArgumentException('A Collection item cannot itself be a Collection.'),
        };
    }

    /** A Collection item target that must also be an #[Entity]-marked class. */
    private static function resolveEntityItemTarget(Collection $attribute, ReflectionProperty $property): string {
        return self::resolveEntityTarget(self::resolveItemTarget($attribute, $property), $property);
    }

    private static function resolveItemTarget(Collection $attribute, ReflectionProperty $property): string {
        return $attribute->itemTarget ?? throw new InvalidArgumentException(sprintf(
            '%s::$%s is a Collection of %s items, which needs $itemTarget.',
            $property->getDeclaringClass()->getName(),
            $property->getName(),
            $attribute->itemKind->name,
        ));
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
     * Confirms every field this class declares or inherits from an #[Entity]-marked
     * ancestor is actually readable off its own default instance. Applies the same
     * check to every field kind: a populated collection, an empty one, a null
     * OwningReference, and a set Embed object are all equally "initialized".
     * @param ReflectionClass<object> $reflection
     */
    private static function assertDefaultInstanceIsInitialized(ReflectionClass $reflection, object $defaultInstance): void {
        foreach (self::entityFields($reflection) as $property) {
            if (!$property->isInitialized($defaultInstance)) {
                throw new InvalidArgumentException(sprintf(
                    '%s::$%s is not initialized on the default instance.',
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                ));
            }
        }
    }

    /**
     * Reads this class's own default instance into plain data, recursing into Embed
     * and OwningReference values. An Embed value is read through the declared target
     * class, never a runtime subclass: type erasure. An OwningReference value is read
     * through its own actual class: polymorphic, so the class itself is recorded
     * alongside its fields.
     * @param ReflectionClass<object> $governingClass
     * @return array<string, mixed>
     */
    private static function extractDefaultValues(ReflectionClass $governingClass, object $instance): array {
        $values = [];

        foreach (self::entityFields($governingClass) as $property) {
            $attribute = self::fieldKindAttributesOf($property)[0]->newInstance();
            $values[$property->getName()] = self::extractFieldValue($attribute, $property->getValue($instance));
        }

        return $values;
    }

    private static function extractFieldValue(object $attribute, mixed $value): mixed {
        return match (true) {
            $attribute instanceof Embed => $value === null ? null : self::extractDefaultValues(new ReflectionClass($attribute->type), $value),
            $attribute instanceof OwningReference => self::extractOwningReferenceValue($value),
            $attribute instanceof Collection => self::extractCollectionItems($attribute, $value),
            default => $value,
        };
    }

    /** @return array{class: class-string, fields: array<string, mixed>}|null */
    private static function extractOwningReferenceValue(?object $value): ?array {
        if ($value === null) {
            return null;
        }

        return [
            'class' => $value::class,
            'fields' => self::extractDefaultValues(new ReflectionClass($value::class), $value),
        ];
    }

    /** @return mixed[] */
    private static function extractCollectionItems(Collection $attribute, array $items): array {
        return array_map(
            static fn (mixed $item): mixed => match ($attribute->itemKind) {
                FieldKind::Embed => $item === null ? null : self::extractDefaultValues(new ReflectionClass($attribute->itemTarget), $item),
                FieldKind::OwningReference => self::extractOwningReferenceValue($item),
                default => $item,
            },
            $items,
        );
    }

    /**
     * Every property this class declares or inherits from an #[Entity]-marked
     * ancestor, that also carries a field-kind attribute itself. Reflects each
     * class in the ancestor chain separately rather than calling getProperties()
     * once on the most-derived class: that single call silently drops a private
     * property declared by an ancestor, only seeing protected/public ones there.
     * @param ReflectionClass<object> $reflection
     * @return ReflectionProperty[]
     */
    private static function entityFields(ReflectionClass $reflection): array {
        if (!self::isEntity($reflection->getName())) {
            return [];
        }

        $parent = $reflection->getParentClass();
        $fields = $parent !== false ? self::entityFields($parent) : [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $reflection->getName() && self::fieldKindAttributesOf($property) !== []) {
                $fields[] = $property;
            }
        }

        return $fields;
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
