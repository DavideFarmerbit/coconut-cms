<?php

namespace XyloIsCoding\CoconutCms\Routing;

use ReflectionParameter;

interface RouteValueCaster
{
    public function cast(string $value, ReflectionParameter $parameter): mixed;
}
