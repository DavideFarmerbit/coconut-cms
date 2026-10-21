<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Resolver;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Makes FieldValueResolver act as if one source's values held nothing at one
 * path, or nothing at it nor anywhere under it: how a fallback is worked out.
 */
final readonly class ValueExclusion
{
    private function __construct(
        private ValueSource $source,
        private FieldPath $path,
        private bool $wholeSubtree,
    ) {
    }

    /** $source's values hold nothing at exactly $path. */
    public static function at(ValueSource $source, FieldPath $path): self {
        return new self($source, $path, false);
    }

    /** $source's values hold nothing at $path, nor anywhere under it. */
    public static function under(ValueSource $source, FieldPath $path): self {
        return new self($source, $path, true);
    }

    /** Whether $source's value at $path, a full path in its values, is to be ignored. */
    public function covers(ValueSource $source, FieldPath $path): bool {
        if (!$source->readsSameValuesAs($this->source)) {
            return false;
        }

        return $this->wholeSubtree ? $path->startsWith($this->path) : (string) $path === (string) $this->path;
    }
}
