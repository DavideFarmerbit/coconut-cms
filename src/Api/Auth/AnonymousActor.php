<?php

namespace XyloIsCoding\CoconutCms\Api\Auth;

use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;

/** A caller nobody signed in as: holds no role, so only what's open to everyone is allowed. */
final readonly class AnonymousActor implements Actor
{
    public function hasRole(string $role): bool {
        return false;
    }
}
