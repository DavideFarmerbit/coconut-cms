<?php

namespace XyloIsCoding\CoconutCms\Routing;

final class Router
{
    /** @var Route[] */
    private array $routes = [];

    public function __construct(
        private readonly RouteValueCaster $caster = new DefaultRouteValueCaster(),
    ) {
    }

    /*================================================================================================================*/
    // Router Interface

    public function register(Route $route): void {
        $this->routes[] = $route;
    }

    public function dispatch(Request $request): bool {
        foreach ($this->routes as $route) {
            if ($route->handle($request, $this->caster)) {
                return true;
            }
        }

        return false;
    }

    // ~Router Interface
    /*================================================================================================================*/
}
