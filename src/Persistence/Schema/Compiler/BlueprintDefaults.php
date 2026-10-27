<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;

/**
 * A blueprint's default values, in one of two forms. Dense: every field's
 * default, inherited ones included, as a native class's default instance
 * holds them. Own: only what the type sets itself, as stored; the compiler
 * lays it over its ancestors as compiled in the same batch, so an inherited
 * default follows a parent whose default changed.
 */
final readonly class BlueprintDefaults
{
    private function __construct(
        public ?ObjectNode $dense,
        public ?FieldValues $own,
    ) {
    }

    /** Every field's default, inherited ones included: a native class's default instance, read. */
    public static function dense(ObjectNode $tree): self {
        return new self($tree, null);
    }

    /** Only what the type sets itself, in stored form: what's stored for an already compiled type. */
    public static function own(FieldValues $values): self {
        return new self(null, $values);
    }
}
