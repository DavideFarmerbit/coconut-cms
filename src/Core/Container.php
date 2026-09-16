<?php

namespace XyloIsCoding\CoconutCms\Core;

use RuntimeException;

/**
 * A service registry keyed by a stable string name.
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, callable(): object> */
    private array $factories = [];

    public function bind(string $name, callable $factory): void
    {
        if (isset($this->factories[$name])) {
            throw new RuntimeException("Service \"{$name}\" is already bound.");
        }

        $this->factories[$name] = $factory;
    }

    public function get(string $name): object
    {
        if (!isset($this->instances[$name])) {
            if (!isset($this->factories[$name])) {
                throw new RuntimeException("Unknown service \"{$name}\": nothing was bound to it in this container.");
            }

            $this->instances[$name] = ($this->factories[$name])();
        }

        return $this->instances[$name];
    }
}
