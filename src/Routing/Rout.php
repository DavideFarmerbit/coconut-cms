<?php

namespace XyloIsCoding\CoconutCms\Routing;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionParameter;

final readonly class Rout
{
    private RoutePattern $pattern;
    private Closure $rule;
    private Closure $handler;
    private ?string $dataClass;

    /** @var ReflectionParameter[] */
    private array $params;

    /** @var array<string, true> */
    private array $ruleParamNames;

    /** @var array<string, true> */
    private array $handlerParamNames;

    /**
     * @template T of RoutData
     * @param Closure(Request, T|mixed...): bool $rule
     * @param Closure(Request, T|mixed...): void $handler
     * @param class-string<T>|null $dataClass
     */
    private function __construct(
        string $pattern,
        Closure $rule,
        Closure $handler,
        ?string $dataClass = null,
    ) {
        $this->rule = $rule;
        $this->handler = $handler;
        $this->dataClass = $dataClass;
        
        if ($this->dataClass !== null && !is_subclass_of($this->dataClass, RoutData::class)) {
            throw new InvalidArgumentException(sprintf(
                '%s must extend %s.',
                $this->dataClass,
                RoutData::class,
            ));
        }

        $this->pattern = new RoutePattern($pattern);

        $this->params = $this->dataClass !== null
            ? RouteArguments::ofClass($this->dataClass)
            : RouteArguments::merge($this->rule, $this->handler);

        $this->ruleParamNames = $this->dataClass === null ? RouteArguments::namesOfClosure($this->rule) : [];
        $this->handlerParamNames = $this->dataClass === null ? RouteArguments::namesOfClosure($this->handler) : [];

        RouteArguments::assertSatisfiedByNames($this->params, $this->pattern->paramNames(), $this->dataClass ?? 'Rout closures');
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

        $args = RouteArguments::build($this->params, $captures, $caster, $this->dataClass ?? 'Rout closures');

        if ($this->dataClass !== null) {
            $data = (new ReflectionClass($this->dataClass))->newInstanceArgs($args);

            if (!($this->rule)($request, $data)) {
                return false;
            }

            ($this->handler)($request, $data);
            return true;
        }

        if (!($this->rule)($request, ...array_intersect_key($args, $this->ruleParamNames))) {
            return false;
        }

        ($this->handler)($request, ...array_intersect_key($args, $this->handlerParamNames));
        return true;
    }

    // ~Rout Interface
    /*================================================================================================================*/
}
