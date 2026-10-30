<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

/**
 * A default an operation pins, in stored form: a scalar, an owned object's
 * identifier, or null. Wrapped, so "no default given" is never a magic value.
 */
final readonly class DefaultValue
{
    public function __construct(
        public mixed $value,
    ) {
    }
}
