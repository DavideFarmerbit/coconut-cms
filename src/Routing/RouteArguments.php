<?php

namespace XyloIsCoding\CoconutCms\Routing;

use Closure;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;

final class RouteArguments
{
    /**
     * @param ReflectionParameter[] $parameters
     * @param string[] $availableNames
     */
    public static function assertSatisfiedByNames(array $parameters, array $availableNames, string $label): void
    {
        foreach ($parameters as $parameter) {
            if ($parameter->isOptional() || in_array($parameter->getName(), $availableNames, true)) {
                continue;
            }

            throw new LogicException(sprintf(
                '%s requires parameter "%s" which is not captured by the route pattern.',
                $label,
                $parameter->getName(),
            ));
        }
    }

    /**
     * @param ReflectionParameter[] $parameters
     * @param array<string, string> $captures
     * @return array<int|string, mixed>
     */
    public static function build(array $parameters, array $captures, RouteValueCaster $caster, string $label): array
    {
        $args = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $captures)) {
                if ($parameter->isOptional()) {
                    continue;
                }

                throw new LogicException(sprintf(
                    'Missing route capture "%s" required by %s.',
                    $name,
                    $label,
                ));
            }

            $args[$name] = $caster->cast($captures[$name], $parameter);
        }

        return $args;
    }

    /**
     * @return ReflectionParameter[]
     */
    public static function ofClass(string $class): array
    {
        return (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
    }

    /**
     * @return ReflectionParameter[] the closure's parameters, minus the leading Request one
     */
    public static function ofClosure(Closure $closure): array
    {
        return array_slice((new ReflectionFunction($closure))->getParameters(), 1);
    }

    /**
     * @return array<string, true> a name-set of the closure's own parameters (excluding the leading Request one)
     */
    public static function namesOfClosure(Closure $closure): array
    {
        return array_fill_keys(
            array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), self::ofClosure($closure)),
            true,
        );
    }

    /**
     * Merges two closures' parameters by name. Two closures may each ignore parameters the
     * other declares (same tolerance as an unconsumed route capture), but if both declare the
     * same name, they must agree on its type.
     *
     * @return ReflectionParameter[]
     */
    public static function merge(Closure $a, Closure $b): array
    {
        $signature = static fn (ReflectionParameter $parameter): string => (string) ($parameter->getType() ?? 'mixed');

        $merged = [];
        foreach (self::ofClosure($a) as $parameter) {
            $merged[$parameter->getName()] = $parameter;
        }

        foreach (self::ofClosure($b) as $parameter) {
            $name = $parameter->getName();
            $existing = $merged[$name] ?? null;

            if ($existing === null) {
                $merged[$name] = $parameter;
            } elseif ($signature($existing) !== $signature($parameter)) {
                throw new LogicException(sprintf(
                    'Route closures disagree on parameter "%s": "%s" vs "%s".',
                    $name,
                    $signature($existing),
                    $signature($parameter),
                ));
            }
        }

        return array_values($merged);
    }
}
