<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The one way to turn a Scalar into the plain value it's stored as, so
 * everything past reading a value only sees JSON-safe scalars, and two equal
 * values always compare equal.
 */
final class ScalarValues
{
    public const DATE_FORMAT = 'Y-m-d';
    public const DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    /** How many digits a Decimal keeps after the point, and its column's scale. */
    public const DECIMAL_SCALE = 6;

    /** How many digits a Decimal keeps in total: its column's precision. */
    public const DECIMAL_PRECISION = 20;

    /** How many characters a String, or a string-backed Enum, holds at most: its column's length. */
    public const MAX_STRING_LENGTH = 255;

    /**
     * $value as stored for $type: an Enum as its backing value, a Date as
     * "Y-m-d", a DateTime converted to UTC as "Y-m-d H:i:s", whole seconds.
     * Anything else as is. Throws when $value isn't what $type holds: a
     * Collection's items, or a value from the editor, are never checked by
     * a property type.
     */
    public static function toStorage(ScalarType $type, mixed $value): mixed {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ScalarType::String, ScalarType::Text => is_string($value) ? $value : throw self::unexpected($type, $value, 'string'),
            ScalarType::Decimal => is_string($value) ? self::canonicalDecimal($value) : throw self::unexpected($type, $value, 'string'),
            ScalarType::Int => is_int($value) ? $value : throw self::unexpected($type, $value, 'int'),
            // An int widens to a float, the one conversion PHP makes even under
            // strict_types: so 3 and 3.0 store, and compare, the same.
            ScalarType::Float => is_float($value) || is_int($value) ? (float) $value : throw self::unexpected($type, $value, 'float'),
            ScalarType::Bool => is_bool($value) ? $value : throw self::unexpected($type, $value, 'bool'),
            ScalarType::Enum => $value instanceof BackedEnum ? $value->value : throw self::unexpected($type, $value, BackedEnum::class),
            ScalarType::Date => $value instanceof DateTimeImmutable ? $value->format(self::DATE_FORMAT) : throw self::unexpected($type, $value, DateTimeImmutable::class),
            ScalarType::DateTime => $value instanceof DateTimeImmutable
                ? $value->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_TIME_FORMAT)
                : throw self::unexpected($type, $value, DateTimeImmutable::class),
        };
    }

    /**
     * What toStorage() stored, as the property holds it again: an Enum's case,
     * a Date at midnight in the default timezone, a DateTime in UTC.
     * @param class-string<BackedEnum>|null $enumClass Needed for ScalarType::Enum.
     */
    public static function fromStorage(ScalarType $type, ?string $enumClass, mixed $stored): mixed {
        if ($stored === null) {
            return null;
        }

        return match ($type) {
            ScalarType::Enum => $enumClass::from($stored),
            ScalarType::Date => DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $stored),
            ScalarType::DateTime => DateTimeImmutable::createFromFormat(self::DATE_TIME_FORMAT, $stored, new DateTimeZone('UTC')),
            default => $stored,
        };
    }

    /**
     * $decimal written the way the database gives it back: exactly
     * DECIMAL_SCALE decimal places, no leading zeros. "12.5" and "012.50" are
     * the same number, so they must store, and compare, the same.
     */
    private static function canonicalDecimal(string $decimal): string {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $parts) !== 1) {
            throw new InvalidArgumentException(sprintf('A Decimal scalar must hold a number like "12.50", got "%s".', $decimal));
        }

        [, $sign, $whole, $fraction] = $parts + [3 => ''];
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > self::DECIMAL_SCALE) {
            // MySQL would round it silently.
            throw new InvalidArgumentException(sprintf('Decimal "%s" has more than %d decimal places.', $decimal, self::DECIMAL_SCALE));
        }

        $whole = ltrim($whole, '0') ?: '0';
        $fraction = str_pad($fraction, self::DECIMAL_SCALE, '0');
        $isZero = $whole === '0' && trim($fraction, '0') === '';

        return ($isZero ? '' : $sign) . $whole . '.' . $fraction;
    }

    private static function unexpected(ScalarType $type, mixed $value, string $expected): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('A %s scalar must hold a %s, got %s.', $type->name, $expected, get_debug_type($value)));
    }
}
