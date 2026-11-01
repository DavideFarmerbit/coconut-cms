<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\FieldRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/** A Collection of at least $min items: `new MinItems(1)` for one that can't be empty. */
#[FieldRule('coconut-cms:min-items')]
final readonly class MinItems implements FieldValidatorRule
{
    /** @param int $min The fewest items, at least 0. */
    public function __construct(
        public int $min,
    ) {
        if ($min < 0) {
            throw new InvalidArgumentException(sprintf('A min item count can\'t be negative, got %d.', $min));
        }
    }

    /** Any Collection. */
    public static function fits(): FieldSignature {
        return FieldSignature::collection();
    }

    /** Nothing to check: any min fits any Collection. */
    public function checkUsage(FieldDescriptor $field): void {
    }

    /** Valid when $value holds at least $min items. */
    public function validate(mixed $value): ValidationResult {
        return count($value) >= $this->min
            ? ValidationResult::valid()
            : ValidationResult::invalid(sprintf('must hold at least %d %s', $this->min, $this->min === 1 ? 'item' : 'items'));
    }
}
