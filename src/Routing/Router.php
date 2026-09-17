<?php

namespace XyloIsCoding\CoconutCms\Routing;

final class Router
{
    /** @var Rout[] */
    private array $routs = [];

    public function __construct(
        private readonly RouteValueCaster $caster = new DefaultRouteValueCaster(),
    ) {
    }

    /*================================================================================================================*/
    // Router Interface

    public function register(Rout $rout): void {
        $this->routs[] = $rout;
    }

    public function dispatch(Request $request): bool {
        foreach ($this->routs as $rout) {
            if ($rout->handle($request, $this->caster)) {
                return true;
            }
        }

        return false;
    }

    // ~Router Interface
    /*================================================================================================================*/
}
