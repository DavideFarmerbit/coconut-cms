<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values;

/**
 * Builds a FieldValues one path at a time. Mutable, unlike FieldValues itself:
 * meant to stay local to whatever is building, never shared. Hand out build()'s
 * result instead.
 */
final class FieldValuesBuilder
{
    /** @var array<string, mixed> Keyed by FieldPath, as plain strings. */
    private array $values = [];

    /** Replaces whatever was already set at $path. */
    public function set(FieldPath $path, mixed $value): void {
        $this->values[(string) $path] = $value;
    }

    public function build(): FieldValues {
        return FieldValues::fromArray($this->values);
    }
}
