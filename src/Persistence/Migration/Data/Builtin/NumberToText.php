<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;

/**
 * An Int, Float or Decimal field turned into text, for RetypeField: 150 as
 * "150", 2.5 as "2.5", the Decimal "12.500000" as "12.5". Null stays null;
 * a value following the old default follows the new one.
 */
final readonly class NumberToText implements FieldConverter
{
    public function convert(Captured $old): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();

        return Conversion::of(match (true) {
            $value === null => null,
            is_int($value) => (string) $value,
            is_float($value) => self::floatText($value),
            // A Decimal is stored with every decimal place: trailing zeros say nothing.
            is_string($value) && is_numeric($value) => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value,
            default => throw new InvalidArgumentException(sprintf('NumberToText converts numbers, got %s.', get_debug_type($value))),
        });
    }

    /** $value in plain digits, never with an exponent: 0.00001 as "0.00001". */
    private static function floatText(float $value): string {
        $text = (string) $value;
        if (!str_contains($text, 'E')) {
            return $text;
        }

        return rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
    }
}
