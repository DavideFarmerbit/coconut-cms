<?php

namespace XyloIsCoding\CoconutCms\Storage;

/**
 * A cross-field rule (end date after start date) evaluated against a whole candidate
 * entity's fully-resolved values, not one field's descriptor. FieldValidator can't
 * express this, it only ever sees the single value being set.
 *
 * Attach to a native class via #[PrototypeValidation]. Editor-created prototypes can
 * only pick from a closed menu of built-in implementations, never arbitrary code, the
 * same native-vs-editor-created asymmetry used everywhere else admin-authored schema
 * is more restricted than native code.
 */
interface PrototypeValidator
{
    /** @param array<string, mixed> $values the whole candidate state, current values with a changeset's new ones overlaid */
    public function validate(array $values): bool;

    /** @return array<string, mixed> */
    public function describe(): array;
}
