<?php

namespace XyloIsCoding\CoconutCms\Core;

/**
 * The single object a host app creates at bootstrap to own every service
 * instance needed for the page. Today it's a thin wrapper around Container;
 * it's the seam for whatever other bootstrap-level concerns (config, the
 * current request, ...) show up later.
 */
final class Kernel
{
    private Container $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function bind(Identifier $id, callable $factory): void
    {
        $this->container->bind($id, $factory);
    }

    public function get(Identifier $id): object
    {
        return $this->container->get($id);
    }
}
