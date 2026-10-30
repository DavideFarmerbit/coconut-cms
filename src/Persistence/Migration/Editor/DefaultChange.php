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

    /**
     * $own, what the type sets itself keyed by path in stored form, with this
     * change applied: the value pinned at the path, or the path and
     * everything under it no longer set.
     * @param array<string, mixed> $own
     * @return array<string, mixed>
     */
    public function applyTo(array $own): array {
        if ($this->value !== null) {
            $own[(string) $this->path] = $this->value->value;

            return $own;
        }

        foreach (array_keys($own) as $path) {
            if (FieldPath::fromString((string) $path)->startsWith($this->path)) {
                unset($own[$path]);
            }
        }

        return $own;
    }
}
