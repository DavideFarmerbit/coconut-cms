<?php

namespace XyloIsCoding\CoconutCms\Storage;

/**
 * Checks whether a single value is acceptable for a field. Only ever sees the value
 * being set, never who is setting it, that's FieldPermission's job instead.
 *
 * Attach implementations to a FieldDescriptor's $validators.
 */
interface FieldValidator
{
    /** Whether $value is acceptable for the field this validator was attached to. */
    public function validate(mixed $value): bool;

    /**
     * A machine-readable description of the rule, for surfacing to a client (an
     * editor form rendering "max 255 characters" without re-implementing the check).
     *
     * @return array<string, mixed>
     */
    public function describe(): array;
}
