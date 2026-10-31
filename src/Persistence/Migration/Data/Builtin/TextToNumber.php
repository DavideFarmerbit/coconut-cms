<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * A text field turned into an Int, Float or Decimal one, for RetypeField:
 * "150" as 150, " 2.5 " as 2.5. Text that isn't such a number, "call us" or
 * "1.5" for an Int, does what $onFailure says. Null stays null; a value
 * following the old default follows the new one.
 */
#[Converter('coconut-cms:text-to-number')]
final readonly class TextToNumber implements FieldConverter
{
    public function __construct(
        public ScalarType $to,
        public OnFailure $onFailure,
    ) {
        if (!in_array($to, [ScalarType::Int, ScalarType::Float, ScalarType::Decimal], true)) {
            throw new InvalidArgumentException(sprintf('TextToNumber converts to Int, Float or Decimal, not %s.', $to->name));
        }
    }

    public function convert(Captured $old): Conversion {
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

        $number = $this->numberOf(trim($value));
        if ($number !== null) {
            return Conversion::of($number);
        }

        return $this->onFailure === OnFailure::Default ? Conversion::default() : throw ConversionFailed::of('TextToNumber', $value);
    }

    /** $text as the number it holds, in stored form; null when it holds none. */
    private function numberOf(string $text): int|float|string|null {
        return match ($this->to) {
            ScalarType::Int => preg_match('/^-?\d+$/', $text) === 1 && filter_var($text, FILTER_VALIDATE_INT) !== false ? (int) $text : null,
            ScalarType::Float => is_numeric($text) ? (float) $text : null,
            // Stored canonical once checked: "12.5" becomes "12.500000".
            default => preg_match('/^-?\d+(\.\d+)?$/', $text) === 1 ? $text : null,
        };
    }
}
