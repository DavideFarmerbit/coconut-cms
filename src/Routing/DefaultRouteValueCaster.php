<?php

namespace XyloIsCoding\CoconutCms\Routing;

use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use UnexpectedValueException;
use ValueError;

class DefaultRouteValueCaster implements RouteValueCaster
{
    public function cast(string $value, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if (enum_exists($typeName)) {
            /** @var class-string<\BackedEnum> $typeName */
            $backingType = (new ReflectionEnum($typeName))->getBackingType()?->getName();
            $backedValue = $backingType === 'int' ? $this->toInt($value, $parameter) : $value;

            try {
                return $typeName::from($backedValue);
            } catch (ValueError) {
                throw new UnexpectedValueException(sprintf(
                    'Route capture "%s" value "%s" is not a valid case of %s.',
                    $parameter->getName(),
                    $value,
                    $typeName,
                ));
            }
        }

        return match ($typeName) {
            'string', 'mixed' => $value,
            'int' => $this->toInt($value, $parameter),
            'float' => $this->toFloat($value, $parameter),
            'bool' => $this->toBool($value, $parameter),
            default => throw new UnexpectedValueException(sprintf(
                'Route capture "%s" cannot be cast to unsupported type %s.',
                $parameter->getName(),
                $typeName,
            )),
        };
    }

    private function toInt(string $value, ReflectionParameter $parameter): int
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new UnexpectedValueException(sprintf(
                'Route capture "%s" value "%s" is not a valid integer.',
                $parameter->getName(),
                $value,
            ));
        }

        return (int) $value;
    }

    private function toFloat(string $value, ReflectionParameter $parameter): float
    {
        if (!is_numeric($value)) {
            throw new UnexpectedValueException(sprintf(
                'Route capture "%s" value "%s" is not a valid float.',
                $parameter->getName(),
                $value,
            ));
        }

        return (float) $value;
    }

    private function toBool(string $value, ReflectionParameter $parameter): bool
    {
        return match ($value) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new UnexpectedValueException(sprintf(
                'Route capture "%s" value "%s" is not a valid boolean.',
                $parameter->getName(),
                $value,
            )),
        };
    }
}
