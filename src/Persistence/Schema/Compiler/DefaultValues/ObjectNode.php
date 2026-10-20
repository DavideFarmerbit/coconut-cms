<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\DefaultValues;

use Countable;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * One object's own field values, keyed by FieldName: a blueprint's whole
 * default instance, or what an Embed or an owned object holds. Immutable:
 * with() returns a new node rather than changing this one.
 * @implements IteratorAggregate<FieldName, ValueNode|null>
 */
final readonly class ObjectNode implements ValueNode, IteratorAggregate, Countable
{
    /** @param array<string, ValueNode|null> $fields Keyed by FieldName. */
    private function __construct(
        private array $fields,
    ) {
    }

    public static function empty(): self {
        return new self([]);
    }

    /** @param array<string, ValueNode|null> $fields Keyed by FieldName. */
    public static function fromArray(array $fields): self {
        return new self($fields);
    }

    /** $node itself, or null for no node at all. Throws if $node isn't an ObjectNode. */
    public static function from(?ValueNode $node): ?self {
        if ($node !== null && !$node instanceof self) {
            throw new InvalidArgumentException(sprintf('Expected an ObjectNode, got %s.', $node::class));
        }

        return $node;
    }

    public function with(FieldName $name, ?ValueNode $value): self {
        $fields = $this->fields;
        $fields[(string) $name] = $value;

        return new self($fields);
    }

    public function has(FieldName $name): bool {
        return array_key_exists((string) $name, $this->fields);
    }

    /** Throws when $name isn't held at all; null when it's held as nothing. */
    public function get(FieldName $name): ?ValueNode {
        if (!$this->has($name)) {
            throw new InvalidArgumentException(sprintf('No field named "%s".', $name));
        }

        return $this->fields[(string) $name];
    }

    /**
     * Null both when $name isn't held and when it's held as nothing: for
     * walking an Embed that holds nothing, whose every field then has nothing.
     */
    public function find(FieldName $name): ?ValueNode {
        return $this->fields[(string) $name] ?? null;
    }

    /** @return FieldName[] */
    public function names(): array {
        return array_map(static fn (string $name): FieldName => FieldName::of($name), array_keys($this->fields));
    }

    public function getIterator(): Iterator {
        foreach ($this->fields as $name => $value) {
            yield FieldName::of((string) $name) => $value;
        }
    }

    public function count(): int {
        return count($this->fields);
    }
}
