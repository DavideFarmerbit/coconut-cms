<?php

namespace XyloIsCoding\CoconutCms\Persistence\Permission;

/**
 * Trusted code acting as itself: a migration, a CLI command, internal
 * bookkeeping. Holds every role, so nothing is hidden or refused. Passed
 * explicitly, so code reaching past permissions always says so.
 */
final readonly class SystemActor implements Actor
{
    public function hasRole(string $role): bool {
        return true;
    }
}
