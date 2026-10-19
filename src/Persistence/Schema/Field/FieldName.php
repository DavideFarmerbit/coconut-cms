<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use InvalidArgumentException;
use Stringable;

/**
 * One field's own name within an entity's declared shape.
 */
final readonly class FieldName implements Stringable
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function of(string $value): self {
        if ($value === '') {
            throw new InvalidArgumentException('A field name cannot be empty.');
        }

        return new self($value);
    }

    public function __toString(): string {
        return $this->value;
    }
}
