<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

use Attribute;

/**
 * Marks a native class's constructor-promoted property as a persisted field. Carries
 * only what reflection can't already infer, plain scalar kind comes from the
 * property's own PHP type instead of being redeclared here.
 *
 * For a class-typed property, pair this with Embed (or, once available, Reference) to
 * say whether it's inlined value-object content or a reference to another entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Field
{
    /** @param FieldValidator[] $validators */
    public function __construct(
        public ?string $label = null,
        public ?string $group = null,
        public bool $queryable = false,
        public bool $unique = false,
        public array $validators = [],
    ) {
    }
}
