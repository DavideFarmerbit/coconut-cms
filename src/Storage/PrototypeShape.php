<?php

namespace XyloIsCoding\CoconutCms\Storage;

use BackedEnum;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Storage\Field\Collection;
use XyloIsCoding\CoconutCms\Storage\Field\Embed;
use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Field\PrototypeValidation;
use XyloIsCoding\CoconutCms\Storage\Field\Reference;

/**
 * Builds a native class's FieldDescriptor[] shape from its constructor-promoted
 * properties via reflection. The editor-assembled equivalent builds the same
 * FieldDescriptor[] directly from stored schema rows instead, no reflection involved.
 */
final class PrototypeShape
{
    /**
     * The full effective shape: every level's own fields, base first, concatenated.
     * For a class with no native parent, this is just that one level's own fields.
     *
     * @param class-string $class
     * @return FieldDescriptor[]
     */
    public static function ofClass(string $class): array
    {
        $descriptors = [];
        foreach (self::chainOfClass($class) as $level) {
            array_push($descriptors, ...self::ownFieldsOfClass($level));
        }

        return $descriptors;
    }

    /**
     * Every native ancestor that extends the prototype chain, base first, ending with
     * $class itself. A class with no native parent returns just itself.
     *
     * @param class-string $class
     * @return class-string[]
     */
    public static function chainOfClass(string $class): array
    {
        $chain = [$class];

        $parent = (new ReflectionClass($class))->getParentClass();
        while ($parent !== false) {
            array_unshift($chain, $parent->getName());
            $parent = $parent->getParentClass();
        }

        return $chain;
    }

    /**
     * Only the fields declared at this exact level, not inherited from a native
     * parent, and only constructor parameters carrying #[Field], an unannotated
     * parameter is simply not part of the persisted shape.
     *
     * @param class-string $class
     * @return FieldDescriptor[]
     */
    public static function ownFieldsOfClass(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $class) {
            return [];
        }

        $descriptors = [];
        foreach ($constructor->getParameters() as $parameter) {
            $property = $reflection->getProperty($parameter->getName());
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $field = self::fieldAttribute($property);
            if ($field === null) {
                continue;
            }

            $descriptors[] = self::describe($parameter, $property, $field);
        }

        return $descriptors;
    }

    /**
     * The full effective set of cross-field rules: every level's own
     * #[PrototypeValidation] validators, base first, concatenated. For a class with no
     * native parent, this is just that one level's own.
     *
     * @param class-string $class
     * @return PrototypeValidator[]
     */
    public static function prototypeValidatorsOfClass(string $class): array
    {
        $validators = [];
        foreach (self::chainOfClass($class) as $level) {
            array_push($validators, ...self::ownPrototypeValidatorsOfClass($level));
        }

        return $validators;
    }

    /**
     * Only the cross-field rules declared at this exact level via #[PrototypeValidation],
     * not inherited from a native parent.
     *
     * @param class-string $class
     * @return PrototypeValidator[]
     */
    public static function ownPrototypeValidatorsOfClass(string $class): array
    {
        $attributes = (new ReflectionClass($class))->getAttributes(PrototypeValidation::class);

        return $attributes === [] ? [] : $attributes[0]->newInstance()->validators;
    }

    /**
     * Attributes on a promoted property are only instantiable through the property's
     * own reflection, not the constructor parameter's, even though both expose them.
     */
    private static function fieldAttribute(ReflectionProperty $property): ?Field
    {
        $attributes = $property->getAttributes(Field::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private static function describe(ReflectionParameter $parameter, ReflectionProperty $property, Field $field): FieldDescriptor
    {
        $name = $parameter->getName();
        $label = $field->label ?? ucfirst($name);
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->allowsNull() || !$type->isBuiltin() && !class_exists($type->getName())) {
            throw new LogicException(sprintf('Field "%s" on %s needs a single, non-nullable, resolvable type.', $name, $parameter->getDeclaringClass()?->getName()));
        }

        $typeName = $type->getName();

        $embed = $property->getAttributes(Embed::class);
        $reference = $property->getAttributes(Reference::class);
        if ($embed !== [] && $reference !== []) {
            throw new LogicException(sprintf('Field "%s" cannot carry both #[Embed] and #[Reference].', $name));
        }

        if ($embed !== []) {
            return FieldDescriptor::embed(
                name: $name,
                shapeClass: $typeName,
                label: $label,
                group: $field->group,
                validators: $field->validators,
                permission: $field->permission,
            );
        }

        if ($reference !== []) {
            /** @var Reference $referenceAttribute */
            $referenceAttribute = $reference[0]->newInstance();

            return FieldDescriptor::reference(
                name: $name,
                shapeClass: $typeName,
                ownership: $referenceAttribute->ownership,
                label: $label,
                group: $field->group,
                queryable: $field->queryable,
                unique: $field->unique,
                validators: $field->validators,
                permission: $field->permission,
            );
        }

        if ($typeName === 'array') {
            $collectionAttributes = $property->getAttributes(Collection::class);
            if ($collectionAttributes === []) {
                throw new LogicException(sprintf('Field "%s" is an array and needs #[Collection] to say what it holds.', $name));
            }

            /** @var Collection $collection */
            $collection = $collectionAttributes[0]->newInstance();

            return FieldDescriptor::collection(
                name: $name,
                itemKind: $collection->itemKind,
                referencedShape: $collection->of,
                label: $label,
                group: $field->group,
                ownership: $collection->ownership,
                validators: $field->validators,
                permission: $field->permission,
            );
        }

        if (enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class)) {
            /** @var class-string<BackedEnum> $typeName */
            return FieldDescriptor::choice(
                name: $name,
                options: array_map(static fn (BackedEnum $case): int|string => $case->value, $typeName::cases()),
                label: $label,
                group: $field->group,
                queryable: $field->queryable,
                validators: $field->validators,
                permission: $field->permission,
            );
        }

        $kind = match ($typeName) {
            'string' => FieldKind::String,
            'int' => FieldKind::Int,
            'float' => FieldKind::Float,
            'bool' => FieldKind::Bool,
            default => throw new LogicException(sprintf(
                'Field "%s" has type %s, which needs #[Embed] or #[Reference] since it\'s a class, or isn\'t supported yet.',
                $name,
                $typeName,
            )),
        };

        return FieldDescriptor::scalar(
            name: $name,
            kind: $kind,
            label: $label,
            group: $field->group,
            queryable: $field->queryable,
            unique: $field->unique,
            validators: $field->validators,
            permission: $field->permission,
        );
    }
}
