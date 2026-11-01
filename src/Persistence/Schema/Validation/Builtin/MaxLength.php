<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\FieldRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/**
 * Text of at most $max characters: `new MaxLength(80)`. Fits a String or Text
 * field, or a Collection of them. Null passes: whether a field may be empty is
 * its nullability's say.
 */
#[FieldRule('coconut-cms:max-length')]
final readonly class MaxLength implements FieldValidatorRule
{
    /** @param int $max The most characters, at least 0. */
    public function __construct(
        public int $max,
    ) {
        if ($max < 0) {
            throw new InvalidArgumentException(sprintf('A max length can\'t be negative, got %d.', $max));
        }
    }

    /** A String or a Text. */
    public static function fits(): FieldSignature {
        return FieldSignature::text();
    }

    /** Nothing to check: any max fits any text. */
    public function checkUsage(FieldDescriptor $field): void {
    }

    /** Valid when $value has at most $max characters. */
    public function validate(mixed $value): ValidationResult {
        return $value === null || mb_strlen($value) <= $this->max
            ? ValidationResult::valid()
            : ValidationResult::invalid(sprintf('must be at most %d characters', $this->max));
    }

    /** {"max": int} */
    public function toStorage(): array {
        return ['max' => $this->max];
    }

    /** @param array{max: int} $stored */
    public static function fromStorage(array $stored): static {
        return new static($stored['max']);
    }
}
