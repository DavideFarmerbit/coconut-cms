<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;

/**
 * One cross-field validation rule attachable to a shape through a
 * #[PrototypeValidator] attribute. Resolves whichever of the shape's own fields it
 * needs from the entity passed to validate().
 *
 * Its constructor's parameters are its configuration: what a stored schema
 * keeps and an editor save gives, read through ConstructorShape. Each is a
 * public property of the same name.
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
     * Validates the whole candidate entity, before it's saved: its values,
     * whatever its type's source.
     */
    public function validate(EntityView $entity): ValidationResult;
}
