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

    /** The same change on type $type: what a type rename makes of it. */
    public function onType(string $type): self {
        return new self($type, $this->path, $this->value);
    }

    /** The same change at $path: what a field rename along it makes of it. */
    public function onPath(FieldPath $path): self {
        return new self($this->type, $path, $this->value);
    }

    /** The same change pinning $value: a type renamed that an owned slot's pin names. */
    public function pinning(DefaultValue $value): self {
        return new self($this->type, $this->path, $value);
    }

    /** Whether it pins a value, rather than inheriting. */
    public function pins(): bool {
        return $this->value !== null;
    }

    /**
     * $own, what the type sets itself keyed by path in stored form, with this
     * change applied: the value pinned at the path, or the path and
     * everything under it no longer set. A Collection's items, a list, are
     * stored as they're read: the count at the path, each item at its index,
     * replacing every item it held.
     * @param array<string, mixed> $own
     * @return array<string, mixed>
     */
    public function applyTo(array $own): array {
        $value = $this->value?->value;
        if ($this->value !== null && !is_array($value)) {
            $own[(string) $this->path] = $value;

            return $own;
        }

        foreach (array_keys($own) as $path) {
            if (FieldPath::fromString((string) $path)->startsWith($this->path)) {
                unset($own[$path]);
            }
        }

        if (is_array($value)) {
            $own[(string) $this->path] = count($value);
            foreach (array_values($value) as $index => $item) {
                $own[(string) $this->path->traverse($index)] = $item;
            }
        }

        return $own;
    }
}
