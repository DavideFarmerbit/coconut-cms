<?php

namespace XyloIsCoding\CoconutCms\Storage\Permission;

/**
 * Whoever is attempting an action, checked by a SchemaPermission (and, later,
 * FieldPermission). Deliberately minimal: this project has no authentication system
 * yet, only the smallest shape a role-based permission check actually needs.
 */
interface Actor
{
    public function hasRole(string $role): bool;
}
