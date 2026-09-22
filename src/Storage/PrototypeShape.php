<?php

namespace XyloIsCoding\CoconutCms\Storage;

use BackedEnum;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Storage\Field\Embed;
use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;

/**
 * Builds a native class's FieldDescriptor[] shape from its constructor-promoted
 * properties via reflection. The editor-assembled equivalent builds the same
 * FieldDescriptor[] directly from stored schema rows instead, no reflection involved.
 */
final class PrototypeShape
{
    /**
     * Only constructor parameters carrying #[Field] are included, an unannotated
     * parameter is simply not part of the persisted shape.
     *
     * @param class-string $class
     * @return FieldDescriptor[]
     */
    public static function ofClass(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];

        $descriptors = [];
        foreach ($parameters as $parameter) {
            $property = $reflection->getProperty($parameter->getName());
            $field = self::fieldAttribute($property);
            if ($field === null) {
                continue;
            }

            $descriptors[] = self::describe($parameter, $property, $field);
        }

        return $descriptors;
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

        $embed = $property->getAttributes(Embed::class);
        if ($embed !== []) {
            return FieldDescriptor::embed(
                name: $name,
                shapeClass: $type->getName(),
                label: $label,
                group: $field->group,
                validators: $field->validators,
            );
        }

        $typeName = $type->getName();

        if (enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class)) {
            /** @var class-string<BackedEnum> $typeName */
            return FieldDescriptor::choice(
                name: $name,
                options: array_map(static fn (BackedEnum $case): int|string => $case->value, $typeName::cases()),
                label: $label,
                group: $field->group,
                queryable: $field->queryable,
                validators: $field->validators,
            );
        }

        $kind = match ($typeName) {
            'string' => FieldKind::String,
            'int' => FieldKind::Int,
            'float' => FieldKind::Float,
            'bool' => FieldKind::Bool,
            default => throw new LogicException(sprintf(
                'Field "%s" has type %s, which needs #[Embed] (or, once available, #[Reference]) since it\'s a class, or isn\'t supported yet.',
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
        );
    }
}
