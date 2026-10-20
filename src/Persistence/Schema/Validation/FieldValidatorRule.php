<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;

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

    /**
     * This rule's own configuration as plain data, whatever its constructor
     * needs: what a stored schema keeps, and what fromStorage() rebuilds the
     * rule from.
     * @return array<string, mixed>
     */
    public function toStorage(): array;

    /** @param array<string, mixed> $stored What toStorage() produced. */
    public static function fromStorage(array $stored): static;
}
