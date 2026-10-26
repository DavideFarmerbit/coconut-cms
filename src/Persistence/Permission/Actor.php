<?php

namespace XyloIsCoding\CoconutCms\Persistence\Permission;

/**
 * Who's reading or writing: what a FieldPermission's roles are checked
 * against. The project implements it over its own users; persistence never
 * stores one. Every read and write names one, through Persistence::as().
 */
interface Actor
{
    /** Whether this actor holds $role, as a #[FieldPermission] names it. */
    public function hasRole(string $role): bool;
}
