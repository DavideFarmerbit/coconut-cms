<?php

namespace XyloIsCoding\CoconutCms\Core;

use RuntimeException;

/**
 * A service registry keyed by Identifier (namespace + key), so unrelated
 * bindings can't collide just by happening to pick the same key.
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, callable(): object> */
    private array $factories = [];

    public function bind(Identifier $id, callable $factory): void
    {
        $key = (string) $id;

        if (isset($this->factories[$key])) {
            throw new RuntimeException("Service \"{$key}\" is already bound.");
        }

        $this->factories[$key] = $factory;
    }

    public function get(Identifier $id): object
    {
        $key = (string) $id;

        if (!isset($this->instances[$key])) {
            if (!isset($this->factories[$key])) {
                throw new RuntimeException("Unknown service \"{$key}\": nothing was bound to it in this container.");
            }

            $this->instances[$key] = ($this->factories[$key])();
        }

        return $this->instances[$key];
    }
}
