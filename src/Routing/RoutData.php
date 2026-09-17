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
    final public static function fromCaptures(array $captures, RouteValueCaster $caster = new DefaultRouteValueCaster()): static
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

            $arguments[$name] = $caster->cast($captures[$name], $parameter);
        }

        return (new ReflectionClass(static::class))->newInstanceArgs($arguments);
    }
    
    /*================================================================================================================*/
    // RoutData Interface
    
    /**
     * A RoutData subclass doesn't have to consume every pattern segment (same as a plain closure
     * route can ignore captures it doesn't declare a param for) — it only needs a constructor
     * parameter for the segments it actually wants. What it can't do is require a parameter
     * the pattern will never supply.
     *
     * @param string[] $paramNames
     */
    final public static function assertSatisfiedByCaptureNames(array $paramNames): void
    {
        foreach (self::constructorParameters() as $parameter) {
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
