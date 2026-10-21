<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values;

use Countable;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;

/**
 * One identifier's values, one per leaf, keyed by FieldPath: what
 * SchemaFieldValueStore saves and loads, and what the compiler builds. A null
 * is a value like any other, not an absence. Immutable, so it's safe to share:
 * build one with FieldValuesBuilder.
 * @implements IteratorAggregate<FieldPath, mixed>
 */
final readonly class FieldValues implements IteratorAggregate, Countable
{
    /** @param array<string, mixed> $values Keyed by FieldPath, as plain strings. */
    private function __construct(
        private array $values,
    ) {
    }

    public static function empty(): self {
        return new self([]);
    }

    /** @param array<string, mixed> $values Keyed by FieldPath, as plain strings. */
    public static function fromArray(array $values): self {
        return new self($values);
    }

    public function has(FieldPath $path): bool {
        return array_key_exists((string) $path, $this->values);
    }

    /** Throws when nothing is held at $path: check has() first, since null is a value. */
    public function get(FieldPath $path): mixed {
        if (!$this->has($path)) {
            throw new InvalidArgumentException(sprintf('No value at "%s".', $path));
        }

        return $this->values[(string) $path];
    }

    /** The values at $path itself and everywhere under it. */
    public function under(FieldPath $path): self {
        $key = (string) $path;

        return new self(array_filter(
            $this->values,
            // A FieldName never contains a separator, so matching the string
            // form matches whole segments.
            static fn (string $candidate): bool => $candidate === $key
                || str_starts_with($candidate, $key . FieldPath::TRAVERSE)
                || str_starts_with($candidate, $key . FieldPath::EMBED),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * The values past "$path.", keyed from there: what the object owned at
     * $path, or the Collection item at $path, holds of its own. The value at
     * $path itself isn't included.
     */
    public function within(FieldPath $path): self {
        $prefix = $path . FieldPath::TRAVERSE;

        $within = [];
        foreach ($this->values as $candidate => $value) {
            if (str_starts_with((string) $candidate, $prefix)) {
                $within[substr((string) $candidate, strlen($prefix))] = $value;
            }
        }

        return new self($within);
    }

    public function getIterator(): Iterator {
        foreach ($this->values as $path => $value) {
            yield FieldPath::fromString((string) $path) => $value;
        }
    }

    public function count(): int {
        return count($this->values);
    }

    /**
     * Keyed by FieldPath, as plain strings: for writing to storage and for
     * comparing in tests, never for looking values up.
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return $this->values;
    }
}
