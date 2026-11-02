<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;

/**
 * An Int, Float or Decimal field turned into another of them, for
 * RetypeField: the new field's type. Places it loses are rounded as
 * $rounding says: 2.5 to an Int is 3, half-up. A Decimal is rounded exactly,
 * digit by digit. A number the new type can't hold, past an Int's range or a
 * Decimal's whole digits, or a Float that's no number, does what $onFailure
 * says. Null stays null; a value following the old default follows the new
 * one.
 */
#[Converter('coconut-cms:number-to-number')]
final readonly class NumberToNumber implements FieldConverter
{
    /** The most whole digits a Decimal holds. */
    private const DECIMAL_WHOLE_DIGITS = ScalarValues::DECIMAL_PRECISION - ScalarValues::DECIMAL_SCALE;

    public function __construct(
        public OnFailure $onFailure,
        public Rounding $rounding = Rounding::HalfUp,
    ) {
    }

    public static function from(): FieldSignature {
        return FieldSignature::number();
    }

    public static function to(): FieldSignature {
        return FieldSignature::number();
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();
        if ($value === null) {
            return Conversion::of(null);
        }

        $converted = match (true) {
            is_int($value) => $this->fromInt($value, $to->scalarType()),
            is_float($value) => $this->fromFloat($value, $to->scalarType()),
            is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 => $this->fromDecimal($value, $to->scalarType()),
            default => throw new InvalidArgumentException(sprintf('NumberToNumber converts a number, got %s.', get_debug_type($value))),
        };
        if ($converted !== null) {
            return Conversion::of($converted);
        }

        return $this->onFailure === OnFailure::Default ? Conversion::default() : throw ConversionFailed::of('NumberToNumber', $value);
    }

    /** $value as a $type, in stored form; null when it can't hold it. */
    private function fromInt(int $value, ScalarType $type): int|float|string|null {
        return match ($type) {
            ScalarType::Int => $value,
            ScalarType::Float => (float) $value,
            ScalarType::Decimal => self::fitsDecimal((string) $value) ? (string) $value : null,
            default => throw self::notANumber($type),
        };
    }

    /** $value as a $type, in stored form; null when it can't hold it. */
    private function fromFloat(float $value, ScalarType $type): int|float|string|null {
        if (!is_finite($value)) {
            return null;
        }

        if ($type === ScalarType::Float) {
            return $value;
        }

        if ($type === ScalarType::Int) {
            $rounded = round($value, 0, $this->rounding->mode());

            // A float holds PHP_INT_MAX only as 2^63, already past it.
            return $rounded >= -(float) PHP_INT_MAX - 1 && $rounded < (float) PHP_INT_MAX ? (int) $rounded : null;
        }

        if ($type === ScalarType::Decimal) {
            $decimal = sprintf('%.' . ScalarValues::DECIMAL_SCALE . 'F', round($value, ScalarValues::DECIMAL_SCALE, $this->rounding->mode()));

            return self::fitsDecimal($decimal) ? $decimal : null;
        }

        throw self::notANumber($type);
    }

    /** $value, a Decimal's stored text, as a $type, in stored form; null when it can't hold it. */
    private function fromDecimal(string $value, ScalarType $type): int|float|string|null {
        return match ($type) {
            ScalarType::Decimal => $value,
            ScalarType::Float => (float) $value,
            ScalarType::Int => $this->decimalToInt($value),
            default => throw self::notANumber($type),
        };
    }

    /** $decimal rounded to a whole number, exactly; null past an Int's range. */
    private function decimalToInt(string $decimal): ?int {
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = explode('.', ltrim($decimal, '-')) + [1 => ''];
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if ($fraction !== '' && $this->roundsAway($whole, $fraction)) {
            $whole = self::incremented($whole);
        }

        $limit = $negative ? '9223372036854775808' : (string) PHP_INT_MAX;
        if (strlen($whole) > strlen($limit) || (strlen($whole) === strlen($limit) && strcmp($whole, $limit) > 0)) {
            return null;
        }

        if ($negative && $whole === $limit) {
            return PHP_INT_MIN;
        }

        return $negative ? -(int) $whole : (int) $whole;
    }

    /** Whether a number of $whole digits and a non-empty $fraction rounds away from zero. */
    private function roundsAway(string $whole, string $fraction): bool {
        $first = $fraction[0];

        return match ($this->rounding) {
            Rounding::Down => false,
            Rounding::Up => true,
            Rounding::HalfUp => $first >= '5',
            Rounding::HalfEven => $first > '5'
                || ($first === '5' && (strlen($fraction) > 1 || ((int) $whole[strlen($whole) - 1]) % 2 === 1)),
        };
    }

    /** $digits, a whole number's, plus one. */
    private static function incremented(string $digits): string {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                return substr($digits, 0, $i) . ((int) $digits[$i] + 1) . str_repeat('0', strlen($digits) - $i - 1);
            }
        }

        return '1' . str_repeat('0', strlen($digits));
    }

    /** Whether $decimal's whole digits fit a Decimal. */
    private static function fitsDecimal(string $decimal): bool {
        return strlen(ltrim(explode('.', ltrim($decimal, '-'))[0], '0')) <= self::DECIMAL_WHOLE_DIGITS;
    }

    private static function notANumber(ScalarType $type): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('NumberToNumber gives a number, not %s.', $type->name));
    }
}
