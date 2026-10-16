<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

/**
 * One validation rule attachable to a single field through a #[FieldValidator]
 * attribute.
 */
interface FieldValidatorRule
{
    /**
     * Checks this rule's own configuration against the field it's declared on.
     * Called at registration time, before any row is read or written. Throws when
     * the configuration is invalid.
     */
    public function checkUsage(FieldDescriptor $field): void;

    /**
     * Validates one field's own resolved value.
     */
    public function validate(mixed $value): ValidationResult;
}
