<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
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
     * Anything else as is.
     */
    public static function toStorage(ScalarType $type, mixed $value): mixed {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            ScalarType::Enum => $value instanceof BackedEnum ? $value->value : throw self::unexpected($type, $value, BackedEnum::class),
            ScalarType::Date => $value instanceof DateTimeInterface ? $value->format(self::DATE_FORMAT) : throw self::unexpected($type, $value, DateTimeInterface::class),
            ScalarType::DateTime => $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_TIME_FORMAT)
                : throw self::unexpected($type, $value, DateTimeInterface::class),
            default => $value,
        };
    }

    private static function unexpected(ScalarType $type, mixed $value, string $expected): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('A %s scalar must hold a %s, got %s.', $type->name, $expected, get_debug_type($value)));
    }
}
