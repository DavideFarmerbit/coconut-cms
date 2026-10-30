<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * A change to what an editor-created type sets itself as a default, at one
 * path: pinned to a value, or inherited again. Applied once the save's fixups
 * moved the stored defaults, so its type and path are the final names.
 */
final readonly class DefaultChange
{
    private function __construct(
        public string $type,
        public FieldPath $path,
        public ?DefaultValue $value,
    ) {
    }

    /** $type sets $value itself at $path, whatever its ancestors give. */
    public static function pin(string $type, FieldPath $path, DefaultValue $value): self {
        return new self($type, $path, $value);
    }

    /** $type sets nothing itself at $path, or below it: it follows its ancestors again. */
    public static function inherit(string $type, FieldPath $path): self {
        return new self($type, $path, null);
    }

    /** Whether it pins a value, rather than inheriting. */
    public function pins(): bool {
        return $this->value !== null;
    }
}
