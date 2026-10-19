<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use Countable;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;

/**
 * Every field an entity declares at its own level, keyed by FieldName.
 * Immutable: with() and merge() both return a new collection rather than
 * changing this one.
 * @implements IteratorAggregate<FieldName, FieldDescriptor>
 */
final readonly class FieldDescriptorCollection implements IteratorAggregate, Countable
{
    /** @param array<string, FieldDescriptor> $fields */
    private function __construct(
        private array $fields,
    ) {
    }

    public static function empty(): self {
        return new self([]);
    }

    /** @param array<string, FieldDescriptor> $fields */
    public static function fromArray(array $fields): self {
        return new self($fields);
    }

    public function with(FieldName $name, FieldDescriptor $field): self {
        $fields = $this->fields;
        $fields[(string) $name] = $field;

        return new self($fields);
    }

    public function get(FieldName $name): FieldDescriptor {
        return $this->fields[(string) $name] ?? throw new InvalidArgumentException(sprintf(
            'No field named "%s".',
            $name,
        ));
    }

    public function has(FieldName $name): bool {
        return array_key_exists((string) $name, $this->fields);
    }

    /** @return FieldName[] */
    public function names(): array {
        return array_map(FieldName::of(...), array_keys($this->fields));
    }

    /** $other's own fields win over this collection's on a shared name. */
    public function merge(self $other): self {
        return new self([...$this->fields, ...$other->fields]);
    }

    public function getIterator(): Iterator {
        foreach ($this->fields as $name => $field) {
            yield FieldName::of($name) => $field;
        }
    }

    public function count(): int {
        return count($this->fields);
    }
}
