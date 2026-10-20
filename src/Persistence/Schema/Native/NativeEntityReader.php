<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

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
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

/**
 * Reflects a native class's attributes into an EntityBlueprint, the
 * compiler's raw input for that one class.
 */
final class NativeEntityReader
{
    /**
     * @param class-string $class
     */
    public static function read(string $class): EntityBlueprint {
        $reflection = new ReflectionClass($class);

        $entity = self::resolveEntityAttribute($reflection);
        $fields = self::resolveFields($reflection);
        $fieldPermissions = self::resolveFieldPermissions($reflection, $fields);
        $fieldValidators = self::resolveFieldValidators($reflection, $fields);
        $uniqueGroups = self::resolveUniqueGroups($reflection, $fields);
        $prototypeValidators = self::resolvePrototypeValidators($reflection, $fields);
        $defaultInstance = self::buildDefaultInstance($reflection);

        self::assertDefaultInstanceIsInitialized($reflection, $defaultInstance);

        return new EntityBlueprint(
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
     * The nearest #[Entity]-marked ancestor, skipping over any non-#[Entity]
     * class in between: such a class contributes no field of its own, but it
     * doesn't break the chain to whatever real entity is still further up.
     * @param ReflectionClass<object> $reflection
     */
    private static function resolveParent(ReflectionClass $reflection): ?string {
        for ($current = $reflection->getParentClass(); $current !== false; $current = $current->getParentClass()) {
            if (self::isEntity($current->getName())) {
                return $current->getName();
            }
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function resolveFields(ReflectionClass $reflection): FieldDescriptorCollection {
        $fields = [];

        foreach (self::ownProperties($reflection) as $property) {
            $fields[$property->getName()] = self::resolveFieldDescriptor($property);
        }

        return FieldDescriptorCollection::fromArray($fields);
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
            $attribute instanceof ValueObject => FieldDescriptor::valueObject($name, self::resolveValueObjectClass(self::declaredClassOf($property), $property)),
            $attribute instanceof Embed => FieldDescriptor::embed($name, self::resolveEntityTarget(self::declaredClassOf($property), $property)),
            $attribute instanceof Reference => FieldDescriptor::reference($name, self::resolveEntityTarget(self::declaredClassOf($property), $property)),
            $attribute instanceof OwningReference => FieldDescriptor::owningReference($name, self::resolveEntityTarget(self::declaredClassOf($property), $property)),
            $attribute instanceof Collection => FieldDescriptor::collection($name, self::resolveCollectionItemDescriptor($name, $attribute, $property)),
        };
    }

    /**
     * The single class $property is declared as, nullable or not: where an
     * Embed, Reference, OwningReference or ValueObject field's target comes
     * from, rather than from its attribute.
     */
    private static function declaredClassOf(ReflectionProperty $property): string {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s must be declared as a single class, which is its target; found "%s".',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $type ?? 'no type',
            ));
        }

        return $type->getName();
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

    /** A value object class must store and rebuild itself. */
    private static function resolveValueObjectClass(string $class, ReflectionProperty $property): string {
        if (!is_subclass_of($class, StorableValue::class)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is a ValueObject of "%s", which does not implement %s.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $class,
                StorableValue::class,
            ));
        }

        return $class;
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
            FieldKind::ValueObject => FieldDescriptor::valueObject($name, self::resolveValueObjectClass(self::resolveItemTarget($attribute, $property), $property)),
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
     */
    private static function resolveFieldPermissions(ReflectionClass $reflection, FieldDescriptorCollection $fields): FieldPermissionCollection {
        $permissions = [];

        foreach (self::ownProperties($reflection) as $property) {
            $attributes = $property->getAttributes(FieldPermission::class);
            if ($attributes === []) {
                continue;
            }

            $name = FieldName::of($property->getName());
            if (!$fields->has($name)) {
                throw new InvalidArgumentException(sprintf(
                    '%s::$%s carries #[FieldPermission], but is not one of its own fields.',
                    $reflection->getName(),
                    $property->getName(),
                ));
            }

            $permissions[$property->getName()] = $attributes[0]->newInstance();
        }

        return FieldPermissionCollection::fromArray($permissions);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function resolveFieldValidators(ReflectionClass $reflection, FieldDescriptorCollection $fields): FieldValidatorCollection {
        $validators = [];

        foreach (self::ownProperties($reflection) as $property) {
            $attributes = $property->getAttributes(FieldValidator::class);
            if ($attributes === []) {
                continue;
            }

            $name = FieldName::of($property->getName());
            if (!$fields->has($name)) {
                throw new InvalidArgumentException(sprintf(
                    '%s::$%s carries #[FieldValidator], but is not one of its own fields.',
                    $reflection->getName(),
                    $property->getName(),
                ));
            }

            $rules = $attributes[0]->newInstance()->rules;
            foreach ($rules as $rule) {
                $rule->checkUsage($fields->get($name));
            }

            $validators[$property->getName()] = $rules;
        }

        return FieldValidatorCollection::fromArray($validators);
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return FieldName[][]
     */
    private static function resolveUniqueGroups(ReflectionClass $reflection, FieldDescriptorCollection $fields): array {
        $groups = [];

        foreach ($reflection->getAttributes(Unique::class) as $attribute) {
            $group = [];

            foreach ($attribute->newInstance()->fields as $name) {
                $fieldName = FieldName::of($name);
                if (!$fields->has($fieldName)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s declares #[Unique] over "%s", which is not one of its own fields.',
                        $reflection->getName(),
                        $name,
                    ));
                }

                $group[] = $fieldName;
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return \XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule[]
     */
    private static function resolvePrototypeValidators(ReflectionClass $reflection, FieldDescriptorCollection $fields): array {
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
     * Builds one throwaway default instance, used only to read default values off
     * of; never kept or exposed.
     * @param ReflectionClass<object> $reflection
     */
    private static function buildDefaultInstance(ReflectionClass $reflection): object {
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

            return $method->invoke(null);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidArgumentException(sprintf(
                '%s needs a zero-argument constructor, or a static method marked #[DefaultInstance].',
                $reflection->getName(),
            ));
        }

        return $reflection->newInstance();
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

    /*================================================================================================================*/
    // Default Values Extraction

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
            $values[$property->getName()] = self::extractFieldValue($attribute, $property, $property->getValue($instance));
        }

        return $values;
    }

    /** A ValueObject is read as the string it stores as, never as the object itself. */
    private static function extractFieldValue(object $attribute, ReflectionProperty $property, mixed $value): mixed {
        return match (true) {
            $attribute instanceof Embed => $value === null ? null : self::extractDefaultValues(new ReflectionClass(self::declaredClassOf($property)), $value),
            $attribute instanceof ValueObject => $value?->toStorage(),
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
                FieldKind::ValueObject => $item?->toStorage(),
                default => $item,
            },
            $items,
        );
    }

    // ~Default Values Extraction
    /*================================================================================================================*/

    /**
     * Every property this class declares or inherits, through any number of
     * #[Entity]-marked or plain ancestors alike, that belongs to an
     * #[Entity]-marked class and carries a field-kind attribute itself. Always
     * climbs the full PHP ancestor chain: a non-#[Entity] class contributes no
     * field of its own, but doesn't stop the walk from reaching a real entity
     * further up past it. Reflects each class in the chain separately rather
     * than calling getProperties() once on the most-derived class: that single
     * call silently drops a private property declared by an ancestor, only
     * seeing protected/public ones there.
     * @param ReflectionClass<object> $reflection
     * @return ReflectionProperty[]
     */
    private static function entityFields(ReflectionClass $reflection): array {
        $parent = $reflection->getParentClass();
        $fields = $parent !== false ? self::entityFields($parent) : [];

        if (!self::isEntity($reflection->getName())) {
            return $fields;
        }

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
