<?php

namespace XyloIsCoding\CoconutCms\Routing;

use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;

final class RouteValueCaster
{
    public static function cast(string $value, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if (enum_exists($typeName)) {
            /** @var class-string<\BackedEnum> $typeName */
            $backingType = (new ReflectionEnum($typeName))->getBackingType()?->getName();
            return $typeName::from($backingType === 'int' ? (int) $value : $value);
        }

        return match ($typeName) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => !in_array($value, ['0', '', 'false'], true),
            default => $value,
        };
    }
}
