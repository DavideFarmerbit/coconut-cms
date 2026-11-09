<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use RoundingMode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;

/**
 * What compiled steps call: the built-ins' exact meaning in PHP, on values in
 * stored form. A decimal is a text with DECIMAL_SCALE places, a date
 * "Y-m-d", a datetime "Y-m-d H:i:s" in UTC, an enum its backing value. A
 * value a built-in can't take fails the step, never PHP.
 */
final class Runtime
{
    /** The rounding modes `round` and friends take, by name. */
    public const MODES = ['half-up', 'half-even', 'down', 'up'];

    private const SCALE = ScalarValues::DECIMAL_SCALE;

    /** How many digits a decimal holds before its point. */
    private const WHOLE_DIGITS = ScalarValues::DECIMAL_PRECISION - ScalarValues::DECIMAL_SCALE;

    /** Scale for exact intermediate results: two full decimals multiplied fit. */
    private const WORK_SCALE = 40;

    /** Field $name of a record or an owned object, which must hold it. */
    public static function field(array $object, string $name): mixed {
        if (!array_key_exists($name, $object)) {
            throw new LogicException(sprintf('The values given hold no field "%s".', $name));
        }

        return $object[$name];
    }

    /** Field $name of $object, or null when $object is null: `?.`. */
    public static function safeField(?array $object, string $name): mixed {
        return $object === null ? null : self::field($object, $name);
    }

    public static function fail(string $message): never {
        throw new StepFailed($message);
    }

    /**
     * $body's value, or $fallback's when $body fails.
     * @template T
     * @param callable(): T $body
     * @param callable(): T $fallback
     * @return T
     */
    public static function attempt(callable $body, callable $fallback): mixed {
        try {
            return $body();
        } catch (StepFailed) {
            return $fallback();
        }
    }

    // Integers: every result checked, never silently a float.

    public static function intAdd(int $a, int $b): int {
        return self::int($a + $b);
    }

    public static function intSub(int $a, int $b): int {
        return self::int($a - $b);
    }

    public static function intMul(int $a, int $b): int {
        return self::int($a * $b);
    }

    public static function intMod(int $a, int $b): int {
        return $b === 0 ? self::fail('Remainder of a division by zero.') : $a % $b;
    }

    public static function intNeg(int $a): int {
        return $a === PHP_INT_MIN ? self::fail('An int out of range.') : -$a;
    }

    private static function int(int|float $result): int {
        return is_int($result) ? $result : self::fail('An int out of range.');
    }

    // Decimals: exact, rounded half up to DECIMAL_SCALE places only where a result has more.

    /** $value, an int or a decimal, as a decimal; null stays null. */
    public static function decimalOf(int|string|null $value): ?string {
        return $value === null ? null : self::decimal((string) $value);
    }

    /** $value, an int or a decimal, as a float; null stays null. */
    public static function floatOf(int|float|string|null $value): ?float {
        return $value === null ? null : (float) $value;
    }

    public static function decAdd(string $a, string $b): string {
        return self::decimal(bcadd($a, $b, self::SCALE));
    }

    public static function decSub(string $a, string $b): string {
        return self::decimal(bcsub($a, $b, self::SCALE));
    }

    public static function decMul(string $a, string $b): string {
        return self::decimal(bcmul($a, $b, self::WORK_SCALE));
    }

    public static function decDiv(string $a, string $b): string {
        if (bccomp($b, '0', self::SCALE) === 0) {
            self::fail('A division by zero.');
        }

        return self::decimal(bcdiv($a, $b, self::WORK_SCALE));
    }

    public static function decMod(string $a, string $b): string {
        if (bccomp($b, '0', self::SCALE) === 0) {
            self::fail('Remainder of a division by zero.');
        }

        return self::decimal(bcmod($a, $b, self::SCALE));
    }

    public static function decNeg(string $a): string {
        return self::decimal(bcmul($a, '-1', self::SCALE));
    }

    public static function decCompare(string $a, string $b): int {
        return bccomp($a, $b, self::SCALE);
    }

    /**
     * $number, digits with a point or not, in stored form: rounded half up
     * to DECIMAL_SCALE places, no negative zero. Fails past the digits a
     * decimal holds.
     */
    public static function decimal(string $number): string {
        $rounded = self::roundDigits($number, self::SCALE, 'half-up');
        if (strlen(ltrim(explode('.', $rounded)[0], '-')) > self::WHOLE_DIGITS) {
            self::fail(sprintf('A decimal out of range: %s.', $rounded));
        }

        return $rounded;
    }

    // Floats: a result that isn't a finite number fails.

    public static function floatAdd(float $a, float $b): float {
        return self::finite($a + $b);
    }

    public static function floatSub(float $a, float $b): float {
        return self::finite($a - $b);
    }

    public static function floatMul(float $a, float $b): float {
        return self::finite($a * $b);
    }

    public static function floatDiv(float $a, float $b): float {
        return $b == 0.0 ? self::fail('A division by zero.') : self::finite($a / $b);
    }

    public static function floatMod(float $a, float $b): float {
        return $b == 0.0 ? self::fail('Remainder of a division by zero.') : self::finite(fmod($a, $b));
    }

    private static function finite(float $result): float {
        return is_finite($result) ? $result : self::fail('A float out of range.');
    }

    // Rounding.

    /** $value rounded to $places, by $mode; the same type back. */
    public static function round(int|float|string $value, int $places, string $mode): int|float|string {
        return match (true) {
            is_int($value) => $value,
            is_float($value) => self::finite(round($value, $places, self::roundingMode($mode))),
            default => self::decimal(self::roundDigits($value, $places, $mode)),
        };
    }

    public static function floor(float|string $value): float|string {
        return is_float($value) ? floor($value) : self::decimal(self::roundDigits($value, 0, bccomp($value, '0', self::SCALE) < 0 ? 'up' : 'down'));
    }

    public static function ceil(float|string $value): float|string {
        return is_float($value) ? ceil($value) : self::decimal(self::roundDigits($value, 0, bccomp($value, '0', self::SCALE) < 0 ? 'down' : 'up'));
    }

    public static function abs(int|float|string $value): int|float|string {
        return match (true) {
            is_int($value) => $value === PHP_INT_MIN ? self::fail('An int out of range.') : abs($value),
            is_float($value) => abs($value),
            default => ltrim($value, '-'),
        };
    }

    private static function roundingMode(string $mode): RoundingMode {
        return match ($mode) {
            'half-up' => RoundingMode::HalfAwayFromZero,
            'half-even' => RoundingMode::HalfEven,
            'down' => RoundingMode::TowardsZero,
            'up' => RoundingMode::AwayFromZero,
            default => throw new LogicException(sprintf('Unknown rounding mode "%s".', $mode)),
        };
    }

    /**
     * $number, digits with any number of places, rounded to $places by
     * $mode, then written with DECIMAL_SCALE places. Exact: bcmath only.
     */
    private static function roundDigits(string $number, int $places, string $mode): string {
        $negative = str_starts_with($number, '-');
        $magnitude = ltrim($number, '-');
        $factor = bcpow('10', (string) $places);
        $scaled = bcmul($magnitude, $factor, self::WORK_SCALE);
        $whole = bcadd($scaled, '0', 0);
        $fraction = bcsub($scaled, $whole, self::WORK_SCALE);
        $half = bccomp($fraction, '0.5', self::WORK_SCALE);

        $up = match ($mode) {
            'down' => false,
            'up' => bccomp($fraction, '0', self::WORK_SCALE) > 0,
            'half-up' => $half >= 0,
            'half-even' => $half > 0 || ($half === 0 && bcmod($whole, '2', 0) === '1'),
            default => throw new LogicException(sprintf('Unknown rounding mode "%s".', $mode)),
        };
        if ($up) {
            $whole = bcadd($whole, '1', 0);
        }

        $result = bcdiv($whole, $factor, self::SCALE);
        if (bccomp($result, '0', self::SCALE) === 0) {
            return bcadd('0', '0', self::SCALE);
        }

        return ($negative ? '-' : '') . $result;
    }

    // Conversions.

    /** A text going into a String field: fails past its length. */
    public static function fitString(?string $text): ?string {
        if ($text !== null && mb_strlen($text) > ScalarValues::MAX_STRING_LENGTH) {
            self::fail(sprintf('A text of %d characters doesn\'t fit a string, which holds %d.', mb_strlen($text), ScalarValues::MAX_STRING_LENGTH));
        }

        return $text;
    }

    public static function intFromText(string $text): int {
        $int = preg_match('/^-?\d+$/', $text) === 1 ? filter_var($text, FILTER_VALIDATE_INT) : false;

        return $int === false ? self::fail(sprintf('"%s" is not an int.', $text)) : $int;
    }

    public static function intFromNumber(float|string $number, string $mode): int {
        if (is_float($number)) {
            $rounded = round($number, 0, self::roundingMode($mode));

            // A float holds PHP_INT_MAX only as 2^63, already past it.
            return $rounded >= -(float) PHP_INT_MAX - 1 && $rounded < (float) PHP_INT_MAX ? (int) $rounded : self::fail('An int out of range.');
        }

        $whole = explode('.', self::roundDigits($number, 0, $mode))[0];
        $int = filter_var($whole, FILTER_VALIDATE_INT);

        return $int === false ? self::fail('An int out of range.') : $int;
    }

    public static function decimalFromText(string $text): string {
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            self::fail(sprintf('"%s" is not a decimal.', $text));
        }

        if (strlen(rtrim(explode('.', $text . '.')[1], '0')) > self::SCALE) {
            self::fail(sprintf('"%s" has more than %d decimal places.', $text, self::SCALE));
        }

        return self::decimal($text);
    }

    /** $value's shortest exact digits, rounded half up to DECIMAL_SCALE places. */
    public static function decimalFromFloat(float $value): string {
        return is_finite($value) ? self::decimal(self::floatText($value)) : self::fail('A float that is not a number.');
    }

    public static function floatFromText(string $text): float {
        return preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $text) === 1 ? self::finite((float) $text) : self::fail(sprintf('"%s" is not a float.', $text));
    }

    /** $value as text, by its type: the digits a person reads, never an exponent. */
    public static function textOf(int|float|string|bool $value, string $type): string {
        return match ($type) {
            'int' => (string) $value,
            'float' => self::floatText($value),
            // A decimal is stored with every place: trailing zeros say nothing.
            'decimal' => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value,
            'bool' => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /** $value in plain digits, never with an exponent: 0.00001 as "0.00001". */
    private static function floatText(float $value): string {
        $text = (string) $value;
        if (!str_contains($text, 'E')) {
            return $text;
        }

        return rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
    }

    /**
     * The backing value $value is among $cases, as stored.
     * @param list<string|int> $cases
     */
    public static function enumOf(string|int $value, array $cases, string $enum): string|int {
        foreach ($cases as $case) {
            if ((string) $case === (string) $value) {
                return $case;
            }
        }

        return self::fail(sprintf('"%s" is no case of %s.', $value, $enum));
    }

    public static function dateFromText(string $text): string {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date !== false && $date->format('Y-m-d') === $text ? $text : self::fail(sprintf('"%s" is not a date like 2024-01-31.', $text));
    }

    /** "2024-01-31 10:00:00" in UTC, or ISO 8601 with Z or an offset, as stored. */
    public static function dateTimeFromText(string $text): string {
        $stored = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text, new DateTimeZone('UTC'));
        if ($stored !== false && $stored->format('Y-m-d H:i:s') === $text) {
            return $text;
        }

        $iso = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $text));
        if ($iso === false || $iso->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $text)) {
            self::fail(sprintf('"%s" is not a datetime like 2024-01-31T10:00:00Z.', $text));
        }

        return $iso->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    // Text.

    /** Without the whitespace PHP's trim() removes: space, \t, \n, \r, \0 and \x0B. */
    public static function trim(string $text): string {
        return trim($text);
    }

    public static function lower(string $text): string {
        return mb_strtolower($text, 'UTF-8');
    }

    public static function upper(string $text): string {
        return mb_strtoupper($text, 'UTF-8');
    }

    /** How many characters, not bytes. */
    public static function length(string $text): int {
        return mb_strlen($text, 'UTF-8');
    }

    /** The characters from $start, counted from 0, $length of them or all the rest. */
    public static function substring(string $text, int $start, ?int $length = null): string {
        if ($start < 0 || ($length !== null && $length < 0)) {
            self::fail('substring takes a start and a length of 0 or more.');
        }

        return mb_substr($text, $start, $length, 'UTF-8');
    }

    public static function truncate(string $text, int $length): string {
        return $length < 0 ? self::fail('truncate takes a length of 0 or more.') : mb_substr($text, 0, $length, 'UTF-8');
    }

    public static function replace(string $text, string $search, string $replacement): string {
        return $search === '' ? self::fail('replace needs something to search for.') : str_replace($search, $replacement, $text);
    }

    /** @return list<string> */
    public static function split(string $text, string $separator): array {
        return $separator === '' ? self::fail('split needs a separator.') : explode($separator, $text);
    }

    // Dates.

    public static function year(string $date): int {
        return (int) substr($date, 0, 4);
    }

    public static function month(string $date): int {
        return (int) substr($date, 5, 2);
    }

    public static function day(string $date): int {
        return (int) substr($date, 8, 2);
    }

    // Comparison.

    /** -1, 0 or 1, by $type: decimals by value, text by code point. */
    public static function compare(mixed $a, mixed $b, string $type): int {
        return $type === 'decimal' ? self::decCompare($a, $b) : $a <=> $b;
    }

    // Lists.

    /** @param list<int|float|string> $items */
    public static function sum(array $items, string $type): int|float|string {
        $total = match ($type) {
            'int' => 0,
            'float' => 0.0,
            default => self::decimal('0'),
        };
        foreach ($items as $item) {
            $total = match ($type) {
                'int' => self::intAdd($total, $item),
                'float' => self::floatAdd($total, $item),
                default => self::decAdd($total, $item),
            };
        }

        return $total;
    }

    /** @param list<mixed> $items */
    public static function any(array $items, callable $test): bool {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The smallest of $values, compared as $type.
     * @param non-empty-list<int|float|string> $values
     */
    public static function min(array $values, string $type): int|float|string {
        $min = array_shift($values);
        foreach ($values as $value) {
            if (self::compare($value, $min, $type) < 0) {
                $min = $value;
            }
        }

        return $min;
    }

    /** @param non-empty-list<int|float|string> $values */
    public static function max(array $values, string $type): int|float|string {
        $max = array_shift($values);
        foreach ($values as $value) {
            if (self::compare($value, $max, $type) > 0) {
                $max = $value;
            }
        }

        return $max;
    }
}
