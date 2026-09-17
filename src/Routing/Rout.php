<?php

namespace XyloIsCoding\CoconutCms\Routing;

use Closure;
use InvalidArgumentException;

final readonly class Rout
{
    private RoutePattern $pattern;

    /**
     * @template T of RoutData
     * @param class-string<T> $dataClass
     * @param Closure(T, Request): bool $rule
     * @param Closure(T, Request): void $handler
     */
    public function __construct(
        string $pattern,
        private string $dataClass,
        private Closure $rule,
        private Closure $handler,
    ) {
        if (!is_subclass_of($this->dataClass, RoutData::class)) {
            throw new InvalidArgumentException(sprintf(
                '%s must extend %s.',
                $this->dataClass,
                RoutData::class,
            ));
        }

        $this->pattern = new RoutePattern($pattern);
        ($this->dataClass)::assertSatisfiedByCaptureNames($this->pattern->paramNames());
    }

    /*================================================================================================================*/
    // Rout Interface

    public function pattern(): string {
        return $this->pattern->pattern();
    }

    public function handle(Request $request): bool {
        $captures = $this->pattern->match($request->url());
        if ($captures === null) {
            return false;
        }

        $data = ($this->dataClass)::fromCaptures($captures);

        if (!($this->rule)($data, $request)) {
            return false;
        }

        ($this->handler)($data, $request);
        return true;
    }

    // ~Rout Interface
    /*================================================================================================================*/
}
