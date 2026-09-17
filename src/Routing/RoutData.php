<?php

namespace XyloIsCoding\CoconutCms\Routing;

use LogicException;
use ReflectionClass;
use ReflectionParameter;

abstract readonly class RoutData
{
    /**
     * @param array<string, string> $captures
     */
    final public static function fromCaptures(array $captures): static
    {
        $arguments = [];
        foreach (self::constructorParameters() as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $captures)) {
                if ($parameter->isOptional()) {
                    continue;
                }

                throw new LogicException(sprintf(
                    'Missing route capture "%s" required by %s.',
                    $name,
                    static::class,
                ));
            }

            $arguments[$name] = RouteValueCaster::cast($captures[$name], $parameter);
        }

        return (new ReflectionClass(static::class))->newInstanceArgs($arguments);
    }
    
    /*================================================================================================================*/
    // RoutData Interface
    
    /**
     * @param string[] $paramNames
     */
    final public static function assertSatisfiedByCaptureNames(array $paramNames): void
    {
        $parameters = self::constructorParameters();

        $parameterNames = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        );

        $unknown = array_diff($paramNames, $parameterNames);
        if ($unknown !== []) {
            throw new LogicException(sprintf(
                '%s has no constructor parameter(s) for route capture(s): %s.',
                static::class,
                implode(', ', $unknown),
            ));
        }

        foreach ($parameters as $parameter) {
            if ($parameter->isOptional() || in_array($parameter->getName(), $paramNames, true)) {
                continue;
            }

            throw new LogicException(sprintf(
                '%s requires constructor parameter "%s" which is not captured by the route pattern.',
                static::class,
                $parameter->getName(),
            ));
        }
    }
    
    // ~RoutData Interface
    /*================================================================================================================*/

    /**
     * @return ReflectionParameter[]
     */
    private static function constructorParameters(): array
    {
        return (new ReflectionClass(static::class))->getConstructor()?->getParameters() ?? [];
    }

}
