<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

use Attribute;
use XyloIsCoding\CoconutCms\Storage\PrototypeValidator;

/**
 * Attaches cross-field rules to a native class, entity-level, not per-field, same
 * reason #[EditorExtensible] is class-level rather than living inside FieldDescriptor.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PrototypeValidation
{
    /** @param PrototypeValidator[] $validators */
    public function __construct(
        public array $validators = [],
    ) {
    }
}
