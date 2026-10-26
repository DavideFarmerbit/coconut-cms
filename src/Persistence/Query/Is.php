<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

use InvalidArgumentException;

/**
 * The conditions a query's where() takes: `Is::greaterThan(1000)`. Values
 * are given as a field's PHP property holds them: an enum case, a
 * DateTimeImmutable, a value object, a saved entity for a Reference. Null is
 * never a value: Is::null() and Is::notNull() ask for it.
 */
final class Is
{
    public static function equalTo(mixed $value): Condition {
        return new Condition(Operator::Equal, [self::present($value)]);
    }

    /** Matches a null too, as PHP's `null != $value` does. */
    public static function notEqualTo(mixed $value): Condition {
        return new Condition(Operator::NotEqual, [self::present($value)]);
    }

    public static function greaterThan(mixed $value): Condition {
        return new Condition(Operator::Greater, [self::present($value)]);
    }

    public static function greaterThanOrEqualTo(mixed $value): Condition {
        return new Condition(Operator::GreaterOrEqual, [self::present($value)]);
    }

    public static function lessThan(mixed $value): Condition {
        return new Condition(Operator::Less, [self::present($value)]);
    }

    public static function lessThanOrEqualTo(mixed $value): Condition {
        return new Condition(Operator::LessOrEqual, [self::present($value)]);
    }

    /** @param non-empty-list<mixed> $values */
    public static function in(array $values): Condition {
        if ($values === []) {
            throw new InvalidArgumentException('Is::in() needs at least one value: nothing is in an empty list.');
        }

        return new Condition(Operator::In, array_map(self::present(...), array_values($values)));
    }

    public static function null(): Condition {
        return new Condition(Operator::IsNull, []);
    }

    public static function notNull(): Condition {
        return new Condition(Operator::IsNotNull, []);
    }

    /** SQL LIKE: "%" for any run of characters, "_" for one. For a String or Text field. */
    public static function like(string $pattern): Condition {
        return new Condition(Operator::Like, [$pattern]);
    }

    /** $value, once it isn't null. */
    private static function present(mixed $value): mixed {
        return $value ?? throw new InvalidArgumentException('A condition never compares with null: use Is::null() or Is::notNull().');
    }
}
