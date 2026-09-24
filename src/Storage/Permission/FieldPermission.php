<?php

namespace XyloIsCoding\CoconutCms\Storage\Permission;

/**
 * Gates who can read or write a field's value on an entity instance. Genuinely
 * different from FieldValidator: a validator only ever sees the value being set, with
 * no notion of who is setting it, permission is about the actor.
 *
 * Attach to a FieldDescriptor's `permission`, `null` meaning no restriction beyond
 * whatever content-type-level access already governs the entity.
 */
interface FieldPermission
{
    public function canRead(Actor $actor): bool;

    public function canWrite(Actor $actor): bool;
}
