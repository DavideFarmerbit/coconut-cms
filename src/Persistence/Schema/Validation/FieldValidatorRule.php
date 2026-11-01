<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;

/**
 * One validation rule attachable to a single field through a #[FieldValidator]
 * attribute.
 *
 * It declares, statically, the fields it checks: fits(). A field that doesn't
 * match is refused, naming the mismatch; the editor offers it only where it
 * matches.
 *
 * Its constructor's parameters are its configuration: what a stored schema
 * keeps and an editor save gives, read through ConstructorShape. Each is a
 * public property of the same name. A variadic `FieldValidatorRule ...$rules`
 * holds other rules, such as OnItems does.
 */
interface FieldValidatorRule
{
    /**
     * The fields it checks, as a whole: a Collection rule declares
     * `FieldSignature::collection()` and gets the list. To check each item,
     * wrap item rules in OnItems.
     */
    public static function fits(): FieldSignature;

    /**
     * Checks this rule's own configuration against the field it's declared on,
     * once fits() matched: what depends on its parameters. Called at
     * registration time, before any row is read or written. Throws when the
     * configuration is invalid.
     */
    public function checkUsage(FieldDescriptor $field): void;

    /**
     * Validates one field's value, before it's saved, as a property holds
     * it: a scalar, an enum case, a DateTimeImmutable, a value object; an
     * Embed or an owned object as an EntityView, or null for an empty slot; a
     * Collection as the list of its items, each in that same form.
     */
    public function validate(mixed $value): ValidationResult;
}
