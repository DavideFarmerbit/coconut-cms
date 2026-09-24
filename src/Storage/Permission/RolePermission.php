<?php

namespace XyloIsCoding\CoconutCms\Storage\Permission;

/** The common-case SchemaPermission: an actor needs one specific role for both reading and writing. */
final readonly class RolePermission implements SchemaPermission
{
    public function __construct(
        public string $role,
    ) {
    }

    public function canRead(Actor $actor): bool
    {
        return $actor->hasRole($this->role);
    }

    public function canWrite(Actor $actor): bool
    {
        return $actor->hasRole($this->role);
    }
}
