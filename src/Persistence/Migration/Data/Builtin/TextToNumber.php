<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * A text field turned into an Int, Float or Decimal one, for RetypeField,
 * the number type the new field's: "150" as 150, " 2.5 " as 2.5. Text that
 * isn't such a number, "call us" or "1.5" for an Int, does what $onFailure
 * says. Null stays null; a value following the old default follows the new
 * one.
 */
#[Converter('coconut-cms:text-to-number')]
final readonly class TextToNumber implements FieldConverter
{
    public function __construct(
        public OnFailure $onFailure,
    ) {
    }

    public static function from(): FieldSignature {
        return FieldSignature::text();
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

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('TextToNumber converts text, got %s.', get_debug_type($value)));
        }

        $number = self::numberOf(trim($value), $to->scalarType());
        if ($number !== null) {
            return Conversion::of($number);
        }

        return $this->onFailure === OnFailure::Default ? Conversion::default() : throw ConversionFailed::of('TextToNumber', $value);
    }

    /** $text as the $type number it holds, in stored form; null when it holds none. */
    private static function numberOf(string $text, ScalarType $type): int|float|string|null {
        return match ($type) {
            ScalarType::Int => preg_match('/^-?\d+$/', $text) === 1 && filter_var($text, FILTER_VALIDATE_INT) !== false ? (int) $text : null,
            ScalarType::Float => is_numeric($text) ? (float) $text : null,
            // Stored canonical once checked: "12.5" becomes "12.500000".
            ScalarType::Decimal => preg_match('/^-?\d+(\.\d+)?$/', $text) === 1 ? $text : null,
            default => throw new InvalidArgumentException(sprintf('TextToNumber gives a number, not %s.', $type->name)),
        };
    }
}
