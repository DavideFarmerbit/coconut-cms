<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\FieldRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/**
 * A number within bounds, both included: `new Range(min: 0, max: 100)`. Either
 * bound may be left out, not both. Fits an Int, Float or Decimal field;
 * OnItems puts it on a Collection's items. A Decimal is compared exactly, against each bound
 * rounded to a Decimal's own places. Null passes: whether a field may be empty
 * is its nullability's say.
 */
#[FieldRule('coconut-cms:range')]
final readonly class Range implements FieldValidatorRule
{
    /**
     * @param float|null $min The smallest value allowed; null for none.
     * @param float|null $max The largest value allowed; null for none.
     */
    public function __construct(
        public ?float $min = null,
        public ?float $max = null,
    ) {
        if ($min === null && $max === null) {
            throw new InvalidArgumentException('A range needs a min, a max, or both.');
        }

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException(sprintf('A range\'s min, %s, is above its max, %s.', $min, $max));
        }
    }

    /** An Int, a Float or a Decimal. */
    public static function fits(): FieldSignature {
        return FieldSignature::number();
    }

    /** Nothing to check: any bounds fit any number. */
    public function checkUsage(FieldDescriptor $field): void {
    }

    /** Valid when $value is within both bounds. */
    public function validate(mixed $value): ValidationResult {
        if ($value === null) {
            return ValidationResult::valid();
        }

        $belowMin = $this->min !== null && self::compare($value, $this->min) < 0;
        $aboveMax = $this->max !== null && self::compare($value, $this->max) > 0;
        if (!$belowMin && !$aboveMax) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid(match (true) {
            $this->min === null => sprintf('must be at most %s', $this->max),
            $this->max === null => sprintf('must be at least %s', $this->min),
            default => sprintf('must be between %s and %s', $this->min, $this->max),
        });
    }

    /** -1, 0 or 1 as $value is below, at or above $bound. A Decimal comes as its canonical string. */
    private static function compare(int|float|string $value, float $bound): int {
        if (!is_string($value)) {
            return $value <=> $bound;
        }

        [$valueSign, $valueDigits] = self::scaled($value);
        [$boundSign, $boundDigits] = self::scaled(number_format($bound, ScalarValues::DECIMAL_SCALE, '.', ''));
        if ($valueSign !== $boundSign) {
            return $valueSign <=> $boundSign;
        }

        $magnitude = (strlen($valueDigits) <=> strlen($boundDigits)) ?: (strcmp($valueDigits, $boundDigits) <=> 0);

        return $valueSign < 0 ? -$magnitude : $magnitude;
    }

    /**
     * A decimal with DECIMAL_SCALE places as its sign and its digits, point
     * and leading zeros dropped: two such numbers compare by those alone.
     * @return array{int, string} The sign, -1, 0 or 1, and the digits.
     */
    private static function scaled(string $decimal): array {
        $digits = ltrim(str_replace(['-', '.'], '', $decimal), '0');

        return [$digits === '' ? 0 : (str_starts_with($decimal, '-') ? -1 : 1), $digits];
    }
}
