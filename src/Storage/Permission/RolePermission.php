<?php

namespace XyloIsCoding\CoconutCms\Storage\Permission;

/**
 * The common-case permission check: an actor needs one specific role for both reading
 * and writing. Implements both SchemaPermission and FieldPermission, identical shape,
 * one reusable default rather than two copies of the same role check.
 */
final readonly class RolePermission implements SchemaPermission, FieldPermission
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
