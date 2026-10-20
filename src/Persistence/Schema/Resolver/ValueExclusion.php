<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Resolver;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Makes FieldValueResolver act as if one identifier held nothing at one path,
 * or nothing at it nor anywhere under it: how a fallback is worked out.
 */
final readonly class ValueExclusion
{
    private function __construct(
        private string $identifier,
        private FieldPath $path,
        private bool $wholeSubtree,
    ) {
    }

    /** Nothing held at exactly $path. */
    public static function at(string $identifier, FieldPath $path): self {
        return new self($identifier, $path, false);
    }

    /** Nothing held at $path, nor anywhere under it. */
    public static function under(string $identifier, FieldPath $path): self {
        return new self($identifier, $path, true);
    }

    /** Whether $identifier's value at $path is to be ignored. */
    public function covers(string $identifier, FieldPath $path): bool {
        if ($identifier !== $this->identifier) {
            return false;
        }

        return $this->wholeSubtree ? $path->startsWith($this->path) : (string) $path === (string) $this->path;
    }
}
