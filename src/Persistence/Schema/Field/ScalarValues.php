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
            ScalarType::String, ScalarType::Text, ScalarType::Decimal => is_string($value) ? $value : throw self::unexpected($type, $value, 'string'),
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

    private static function unexpected(ScalarType $type, mixed $value, string $expected): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('A %s scalar must hold a %s, got %s.', $type->name, $expected, get_debug_type($value)));
    }
}
