<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use InvalidArgumentException;
use Stringable;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * One field's own name within an entity's declared shape. Never contains
 * FieldPath's separators, and is never all digits, which in a FieldPath means a
 * Collection index: so a stored path always parses back unambiguously.
 *
 * Never starts with "_" and never holds "__" either: every column and table
 * name the system adds starts with "_", and "__" joins an Embed's fields into
 * one column, so no field can ever land on a name already taken.
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

        if (str_contains($value, FieldPath::EMBED) || str_contains($value, FieldPath::TRAVERSE)) {
            throw new InvalidArgumentException(sprintf(
                'Field name "%s" cannot contain "%s" or "%s".',
                $value,
                FieldPath::EMBED,
                FieldPath::TRAVERSE,
            ));
        }

        if (ctype_digit($value)) {
            throw new InvalidArgumentException(sprintf('Field name "%s" cannot be all digits.', $value));
        }

        if (str_starts_with($value, '_') || str_contains($value, '__')) {
            throw new InvalidArgumentException(sprintf(
                'Field name "%s" cannot start with "_" or hold "__": those are kept for the names the system adds.',
                $value,
            ));
        }

        return new self($value);
    }

    public function __toString(): string {
        return $this->value;
    }
}
