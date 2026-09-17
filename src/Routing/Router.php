<?php

namespace XyloIsCoding\CoconutCms\Routing;

final class Router
{
    /** @var Rout[] */
    private array $routs = [];

    /*================================================================================================================*/
    // Router Interface

    public function register(Rout $rout): void {
        $this->routs[] = $rout;
    }

    public function dispatch(Request $request): bool {
        foreach ($this->routs as $rout) {
            if ($rout->handle($request)) {
                return true;
            }
        }

        return false;
    }

    // ~Router Interface
    /*================================================================================================================*/
}
