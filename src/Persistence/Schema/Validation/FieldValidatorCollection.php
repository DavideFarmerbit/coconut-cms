<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use Countable;
use Iterator;
use IteratorAggregate;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * Every field's own FieldValidatorRules, keyed by FieldName. A field absent
 * here carries no rules, so get() returns an empty array for it rather than
 * throwing: that absence is a meaningful default, not an error.
 * @implements IteratorAggregate<FieldName, FieldValidatorRule[]>
 */
final readonly class FieldValidatorCollection implements IteratorAggregate, Countable
{
    /** @param array<string, FieldValidatorRule[]> $validators */
    private function __construct(
        private array $validators,
    ) {
    }

    public static function empty(): self {
        return new self([]);
    }

    /** @param array<string, FieldValidatorRule[]> $validators */
    public static function fromArray(array $validators): self {
        return new self($validators);
    }

    /** @return FieldValidatorRule[] */
    public function get(FieldName $name): array {
        return $this->validators[(string) $name] ?? [];
    }

    public function has(FieldName $name): bool {
        return array_key_exists((string) $name, $this->validators);
    }

    public function getIterator(): Iterator {
        foreach ($this->validators as $name => $rules) {
            yield FieldName::of($name) => $rules;
        }
    }

    public function count(): int {
        return count($this->validators);
    }
}
