<?php

namespace XyloIsCoding\CoconutCms\Routing;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;

final readonly class Rout
{
    private RoutePattern $pattern;

    /**
     * @template T of RoutData
     * @param Closure(Request, T|mixed...): bool $rule
     * @param Closure(Request, T|mixed...): void $handler
     * @param class-string<T>|null $dataClass
     */
    private function __construct(
        string $pattern,
        private Closure $rule,
        private Closure $handler,
        private ?string $dataClass = null,
    ) {
        if ($this->dataClass !== null && !is_subclass_of($this->dataClass, RoutData::class)) {
            throw new InvalidArgumentException(sprintf(
                '%s must extend %s.',
                $this->dataClass,
                RoutData::class,
            ));
        }

        $this->pattern = new RoutePattern($pattern);

        if ($this->dataClass !== null) {
            ($this->dataClass)::assertSatisfiedByCaptureNames($this->pattern->paramNames());
        }
    }

    /**
     * @template T of RoutData
     * @param string $pattern
     * @param class-string<T> $dataClass
     * @param Closure(Request, T): bool $rule
     * @param Closure(Request, T): void $handler
     * @return Rout
     */
    public static function structured(string $pattern, string $dataClass, Closure $rule, Closure $handler): self {
        return new self($pattern, $rule, $handler, $dataClass);
    }

    /**
     * Raw string captures from the pattern are cast against each closure's own parameter types and unpacked as named args
     * @param string $pattern
     * @param Closure(Request, mixed...): bool $rule
     * @param Closure(Request, mixed...): void $handler
     * @return Rout
     */
    public static function simple(string $pattern, Closure $rule, Closure $handler): self {
        return new self($pattern, $rule, $handler);
    }

    /*================================================================================================================*/
    // Rout Interface

    public function pattern(): string {
        return $this->pattern->pattern();
    }

    public function handle(Request $request, RouteValueCaster $caster): bool {
        $captures = $this->pattern->match($request->url());
        if ($captures === null) {
            return false;
        }

        if ($this->dataClass !== null) {
            $data = ($this->dataClass)::fromCaptures($captures, $caster);

            if (!($this->rule)($request, $data)) {
                return false;
            }

            ($this->handler)($request, $data);
            return true;
        }

        if (!($this->rule)($request, ...self::castArgs($this->rule, $captures, $caster))) {
            return false;
        }

        ($this->handler)($request, ...self::castArgs($this->handler, $captures, $caster));
        return true;
    }

    // ~Rout Interface
    /*================================================================================================================*/

    /**
     * @param array<string, string> $captures
     * @return array<int|string, mixed>
     */
    private static function castArgs(Closure $closure, array $captures, RouteValueCaster $caster): array
    {
        $args = [];

        $parameters = (new ReflectionFunction($closure))->getParameters();
        foreach (array_slice($parameters, 1) as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $captures)) {
                $args[$name] = $caster->cast($captures[$name], $parameter);
            }
        }

        return $args;
    }
}
