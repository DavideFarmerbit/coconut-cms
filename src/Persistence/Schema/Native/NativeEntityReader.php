<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use BackedEnum;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\PrototypeValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
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
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

/**
 * Reflects a native class's attributes into an EntityBlueprint, the
 * compiler's raw input for that one class.
 */
final class NativeEntityReader
{
    /**
     * Reads every class in $classes, and returns what compile() takes. Throws when two of
     * them share an identifier.
     * @param list<class-string> $classes
     * @return array<string, EntityBlueprint> Keyed by identifier.
     */
    public static function readAll(array $classes): array {
        $identifiers = new NativeEntityRegistry();
        $blueprints = [];
        foreach ($classes as $class) {
            $blueprints[$identifiers->register($class)] = self::read($class);
        }

        return $blueprints;
    }

    /**
     * @param class-string $class
     */
    public static function read(string $class): EntityBlueprint {
        $reflection = new ReflectionClass($class);

        $entity = self::resolveEntityAttribute($reflection);
        $identifier = NativeIdentifiers::of($class);
        // Not part of the blueprint: only checked, so a wrong #[Id] fails now
        // rather than on the first save.
        IdProperty::of($class);
        $fields = self::resolveFields($reflection);
        $fieldPermissions = self::resolveFieldPermissions($reflection, $fields);
        $fieldValidators = self::resolveFieldValidators($reflection, $fields);
        $uniqueGroups = self::resolveUniqueGroups($reflection, $fields);
        $prototypeValidators = self::resolvePrototypeValidators($reflection, $fields);
        $defaultInstance = DefaultInstances::build($reflection);

        self::assertDefaultInstanceIsInitialized($reflection, $defaultInstance);

        return new EntityBlueprint(
            identifier: $identifier,
            source: SchemaSource::Native,
            parent: self::resolveParent($reflection),
            table: self::resolveTable($class, $entity->table ?? NativeIdentifiers::tableOf($identifier)),
            editorExtensible: $entity->editorExtensible,
            fields: $fields,
            fieldPermissions: $fieldPermissions,
            fieldValidators: $fieldValidators,
            uniqueGroups: $uniqueGroups,
            prototypeValidators: $prototypeValidators,
            defaultValues: self::defaultValuesReader()->read($defaultInstance),
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
     * $table, unless it starts with "_": every table name the system adds does,
     * so a type's own table never lands on one of them.
     */
    private static function resolveTable(string $class, string $table): string {
        if (str_starts_with($table, '_')) {
            throw new InvalidArgumentException(sprintf('%s\'s table "%s" cannot start with "_": those are kept for the tables the system adds.', $class, $table));
        }

        return $table;
    }

    /**
     * The identifier of the nearest #[Entity]-marked ancestor, skipping over
     * any non-#[Entity] class in between: such a class contributes no field
     * of its own, but it doesn't break the chain to whatever real entity is
     * still further up.
     * @param ReflectionClass<object> $reflection
     */
    private static function resolveParent(ReflectionClass $reflection): ?string {
        for ($current = $reflection->getParentClass(); $current !== false; $current = $current->getParentClass()) {
            if (NativeFields::isEntity($current->getName())) {
                return NativeIdentifiers::of($current->getName());
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
            // A property with no field-kind attribute isn't a field: ignored.
            if (NativeFields::kindAttributesOf($property) !== []) {
                $fields[$property->getName()] = self::resolveFieldDescriptor($property);
            }
        }

        return FieldDescriptorCollection::fromArray($fields);
    }

    private static function resolveFieldDescriptor(ReflectionProperty $property): FieldDescriptor {
        $name = $property->getName();

        $kindAttributes = NativeFields::kindAttributesOf($property);

        if (count($kindAttributes) > 1) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s carries %d field-kind attributes: a field has exactly one kind.',
                $property->getDeclaringClass()->getName(),
                $name,
                count($kindAttributes),
            ));
        }

        $attribute = $kindAttributes[0]->newInstance();

        return match (true) {
            $attribute instanceof Scalar => self::resolveScalar($name, $attribute, $property),
            $attribute instanceof ValueObject => self::valueObjectDescriptor($name, self::resolveValueObjectClass(self::declaredClassOf($property), $property), self::nullabilityOf($property)),
            $attribute instanceof Embed => FieldDescriptor::embed($name, self::resolveEntityTarget(self::declaredClassOf(self::assertNotNullable($property, 'an Embed is never null')), $property)),
            $attribute instanceof Reference => FieldDescriptor::reference($name, self::resolveEntityTarget(self::declaredClassOf(self::assertNullable($property)), $property)),
            $attribute instanceof OwningReference => FieldDescriptor::owningReference($name, self::resolveEntityTarget(self::declaredClassOf($property), $property), self::nullabilityOf($property)),
            $attribute instanceof Collection => FieldDescriptor::collection($name, self::resolveCollectionItemDescriptor($name, $attribute, self::assertNotNullable($property, 'a Collection is never null'))),
        };
    }

    /** Whether $property's declared type allows null. */
    private static function nullabilityOf(ReflectionProperty $property): Nullability {
        return ($property->getType()?->allowsNull() ?? true) ? Nullability::Nullable : Nullability::NotNullable;
    }

    /** Returns $property, once its type doesn't allow null. */
    private static function assertNotNullable(ReflectionProperty $property, string $because): ReflectionProperty {
        if ($property->getType()?->allowsNull() ?? false) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is declared nullable, but %s.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $because,
            ));
        }

        return $property;
    }

    /** Returns $property, once its type allows null: deleting a Reference's target nulls it. */
    private static function assertNullable(ReflectionProperty $property): ReflectionProperty {
        if (!($property->getType()?->allowsNull() ?? true)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is a Reference, so it must be declared nullable: deleting its target nulls it.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        return $property;
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
     * The identifier of an Embed/Reference/OwningReference target: an ordinary
     * #[Entity]-marked class, used differently at this one field site, never a
     * separate kind of target in its own right.
     */
    private static function resolveEntityTarget(string $target, ReflectionProperty $property): string {
        if (!class_exists($target) || !NativeFields::isEntity($target)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s targets "%s", which is not a class marked #[Entity].',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $target,
            ));
        }

        return NativeIdentifiers::of($target);
    }

    /** @param class-string<StorableValue> $class */
    private static function valueObjectDescriptor(string $name, string $class, Nullability $nullability): FieldDescriptor {
        return FieldDescriptor::valueObject($name, $class, $class::indexable(), $nullability);
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
     * A Scalar field, once its property is declared as what its ScalarType
     * holds: hydration puts values back into the property, so a mismatch
     * would only fail when real data is loaded.
     */
    private static function resolveScalar(string $name, Scalar $attribute, ReflectionProperty $property): FieldDescriptor {
        $enumClass = self::resolveEnumClass($property, $attribute);
        $expected = match ($attribute->type) {
            ScalarType::String, ScalarType::Text, ScalarType::Decimal => 'string',
            ScalarType::Int => 'int',
            ScalarType::Float => 'float',
            ScalarType::Bool => 'bool',
            ScalarType::Date, ScalarType::DateTime => DateTimeImmutable::class,
            ScalarType::Enum => $enumClass,
        };

        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->getName() !== $expected) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is ScalarType::%s, so it must be declared as %s, nullable or not; found "%s".',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $attribute->type->name,
                $expected,
                $type ?? 'no type',
            ));
        }

        return FieldDescriptor::scalar($name, $attribute->type, self::nullabilityOf($property), $enumClass, self::enumBackingOf($enumClass));
    }

    /**
     * What $enumClass is backed by, read once here so nothing past the reader
     * needs the enum itself. Null when there's no enum.
     * @param class-string<BackedEnum>|null $enumClass Already checked to be backed.
     */
    private static function enumBackingOf(?string $enumClass): ?ScalarType {
        if ($enumClass === null) {
            return null;
        }

        return (new ReflectionEnum($enumClass))->getBackingType()?->getName() === 'int' ? ScalarType::Int : ScalarType::String;
    }

    /**
     * Returns $enumClass unless it's a pure enum: only a backed enum has a
     * value to store. Null passes through.
     */
    private static function assertBackedEnum(?string $enumClass, ReflectionProperty $property): ?string {
        if ($enumClass !== null && !is_subclass_of($enumClass, BackedEnum::class)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s holds %s, which is not a backed enum: only a backed enum has a value to store.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $enumClass,
            ));
        }

        return $enumClass;
    }

    /**
     * A Scalar::Enum field's enum class: the property's own declared type,
     * never stated on the attribute. Null for any other ScalarType.
     */
    private static function resolveEnumClass(ReflectionProperty $property, Scalar $attribute): ?string {
        if ($attribute->type !== ScalarType::Enum) {
            return null;
        }

        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || !enum_exists($type->getName())) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is ScalarType::Enum, so it must be declared as a single backed enum class.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        return self::assertBackedEnum($type->getName(), $property);
    }

    private static function resolveCollectionItemDescriptor(string $name, Collection $attribute, ReflectionProperty $property): FieldDescriptor {
        return match ($attribute->itemKind) {
            FieldKind::Scalar => FieldDescriptor::scalar(
                $name,
                $attribute->itemScalarType,
                self::resolveItemNullability($attribute, $property),
                self::assertBackedEnum($attribute->itemEnumClass, $property),
                self::enumBackingOf($attribute->itemEnumClass),
            ),
            FieldKind::ValueObject => self::valueObjectDescriptor(
                $name,
                self::resolveValueObjectClass(self::resolveItemTarget($attribute, $property), $property),
                self::resolveItemNullability($attribute, $property),
            ),
            FieldKind::Embed => FieldDescriptor::embed($name, self::resolveEntityItemTarget(self::assertNoItemNullability($attribute, $property, 'an Embed item is never null'), $property)),
            FieldKind::Reference => FieldDescriptor::reference($name, self::resolveEntityItemTarget(self::assertNoItemNullability($attribute, $property, 'a Reference item is always Nullable'), $property)),
            FieldKind::OwningReference => FieldDescriptor::owningReference($name, self::resolveEntityItemTarget($attribute, $property), self::resolveItemNullability($attribute, $property)),
            FieldKind::Collection => throw new InvalidArgumentException('A Collection item cannot itself be a Collection.'),
        };
    }

    /** A Collection's $itemNullability, required for the item kinds that have a choice. */
    private static function resolveItemNullability(Collection $attribute, ReflectionProperty $property): Nullability {
        return $attribute->itemNullability ?? throw new InvalidArgumentException(sprintf(
            '%s::$%s is a Collection of %s items, which needs $itemNullability.',
            $property->getDeclaringClass()->getName(),
            $property->getName(),
            $attribute->itemKind->name,
        ));
    }

    /** Returns $attribute, once it doesn't set $itemNullability. */
    private static function assertNoItemNullability(Collection $attribute, ReflectionProperty $property, string $because): Collection {
        if ($attribute->itemNullability !== null) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s sets $itemNullability, but %s.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $because,
            ));
        }

        return $attribute;
    }

    /** The identifier of a Collection item target that must be an #[Entity]-marked class. */
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

            $permission = $attributes[0]->newInstance();
            if ($permission->read !== null && $property->isReadOnly()) {
                throw new InvalidArgumentException(sprintf(
                    '%s::$%s is readonly and has a read permission: a load hides a field from an actor by leaving it unset, which a readonly property can\'t be.',
                    $reflection->getName(),
                    $property->getName(),
                ));
            }

            $permissions[$property->getName()] = $permission;
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
     * @return list<UniqueGroup>
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

            $groups[] = UniqueGroup::of(...$group);
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
     * Reads a default instance's values. A Reference in a default must be
     * null: how a native class would name a default target isn't designed
     * yet, and the object itself can't be stored.
     */
    private static function defaultValuesReader(): NativeValueReader {
        return new NativeValueReader(static fn (object $target): string => throw new InvalidArgumentException(sprintf(
            'A default instance holds a %s in a Reference: a default Reference must be null for now.',
            $target::class,
        )));
    }

    /**
     * Confirms every field this class declares or inherits from an #[Entity]-marked
     * ancestor is actually readable off its own default instance. Applies the same
     * check to every field kind: a populated collection, an empty one, a null
     * OwningReference, and a set Embed object are all equally "initialized".
     * @param ReflectionClass<object> $reflection
     */
    private static function assertDefaultInstanceIsInitialized(ReflectionClass $reflection, object $defaultInstance): void {
        foreach (NativeFields::of($reflection) as $property) {
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
