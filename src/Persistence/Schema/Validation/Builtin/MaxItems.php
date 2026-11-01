<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\FieldRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/** A Collection of at most $max items: `new MaxItems(5)`. */
#[FieldRule('coconut-cms:max-items')]
final readonly class MaxItems implements FieldValidatorRule
{
    /** @param int $max The most items, at least 0. */
    public function __construct(
        public int $max,
    ) {
        if ($max < 0) {
            throw new InvalidArgumentException(sprintf('A max item count can\'t be negative, got %d.', $max));
        }
    }

    /** Any Collection. */
    public static function fits(): FieldSignature {
        return FieldSignature::collection();
    }

    /** Nothing to check: any max fits any Collection. */
    public function checkUsage(FieldDescriptor $field): void {
    }

    /** Valid when $value holds at most $max items. */
    public function validate(mixed $value): ValidationResult {
        return count($value) <= $this->max
            ? ValidationResult::valid()
            : ValidationResult::invalid(sprintf('must hold at most %d items', $this->max));
    }
}
