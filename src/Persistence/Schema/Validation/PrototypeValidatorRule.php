<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;

/**
 * One cross-field validation rule attachable to a shape through a
 * #[PrototypeValidator] attribute. Resolves whichever of the shape's own fields it
 * needs from the entity passed to validate().
 */
interface PrototypeValidatorRule
{
    /**
     * Checks this rule's own configuration against the shape it's declared on, for
     * example that a field name it references actually exists. Called at
     * registration time, before any row is read or written. Throws when the
     * configuration is invalid.
     */
    public function checkUsage(FieldDescriptorCollection $fields): void;

    /**
     * Validates the whole candidate entity.
     */
    public function validate(object $entity): ValidationResult;

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
