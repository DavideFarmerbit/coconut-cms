<?php

namespace XyloIsCoding\CoconutCms\Routing;

use ReflectionClass;

abstract readonly class RouteData
{
    /**
     * @param array<string, string> $captures
     */
    final public static function fromCaptures(array $captures, RouteValueCaster $caster = new DefaultRouteValueCaster()): static
    {
        $arguments = RouteArguments::build(RouteArguments::ofClass(static::class), $captures, $caster, static::class);

        return (new ReflectionClass(static::class))->newInstanceArgs($arguments);
    }

    /**
     * @param string[] $paramNames
     */
    final public static function assertSatisfiedByCaptureNames(array $paramNames): void
    {
        RouteArguments::assertSatisfiedByNames(RouteArguments::ofClass(static::class), $paramNames, static::class);
    }
}
