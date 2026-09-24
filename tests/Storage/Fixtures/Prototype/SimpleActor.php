<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype;

use XyloIsCoding\CoconutCms\Storage\Permission\Actor;

/** A minimal Actor fixture, a fixed set of role names. */
final readonly class SimpleActor implements Actor
{
    /** @param string[] $roles */
    public function __construct(
        private array $roles = [],
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
