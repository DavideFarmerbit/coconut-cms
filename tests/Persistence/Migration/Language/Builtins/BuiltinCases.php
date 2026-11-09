<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Language\Builtins;

/**
 * What each built-in gives, case by case: the one meaning every backend must
 * give. The PHP backend runs them now; stage 4 runs them on every database.
 * A case reads `old.a`, `old.b`, … of the types given, and sets `new.out`.
 */
final class BuiltinCases
{
    /** What a case expects when the step must fail: the message holds this. */
    public const FAILS = 'fails:';

    /**
     * @return iterable<string, array{string, array<string, string>, array<string, mixed>, string, mixed}>
     *         The expression, the old fields' types, their values, new.out's type, and what it gets.
     */
    public static function all(): iterable {
        // Integers.
        yield 'int add' => ['old.a + old.b', ['a' => 'int', 'b' => 'int'], ['a' => 2, 'b' => 3], 'int', 5];
        yield 'int overflow fails' => ['old.a + 1', ['a' => 'int'], ['a' => PHP_INT_MAX], 'int', self::FAILS . 'out of range'];
        yield 'int divided is an exact decimal' => ['old.a / old.b', ['a' => 'int', 'b' => 'int'], ['a' => 7, 'b' => 2], 'decimal', '3.500000'];
        yield 'int remainder' => ['old.a % 3', ['a' => 'int'], ['a' => -7], 'int', -1];
        yield 'int division by zero fails' => ['old.a / 0', ['a' => 'int'], ['a' => 1], 'decimal', self::FAILS . 'division by zero'];

        // Decimals: exact, rounded half away from zero to 6 places only when a result has more.
        yield 'decimal add' => ['old.a + old.b', ['a' => 'decimal', 'b' => 'decimal'], ['a' => '0.100000', 'b' => '0.200000'], 'decimal', '0.300000'];
        yield 'decimal divided rounds half up' => ['old.a / 3', ['a' => 'decimal'], ['a' => '2.000000'], 'decimal', '0.666667'];
        yield 'negative decimal divided rounds away from zero' => ['old.a / 3', ['a' => 'decimal'], ['a' => '-2.000000'], 'decimal', '-0.666667'];
        yield 'decimal product rounds half up' => ['old.a * old.b', ['a' => 'decimal', 'b' => 'decimal'], ['a' => '0.000005', 'b' => '0.500000'], 'decimal', '0.000003'];
        yield 'decimal remainder' => ['old.a % 2', ['a' => 'decimal'], ['a' => '7.500000'], 'decimal', '1.500000'];
        yield 'no negative zero' => ['-old.a', ['a' => 'decimal'], ['a' => '0.000000'], 'decimal', '0.000000'];
        yield 'decimal out of range fails' => ['old.a * 10', ['a' => 'decimal'], ['a' => '99999999999999.000000'], 'decimal', self::FAILS . 'out of range'];
        yield 'an int widens into a decimal' => ['old.a + old.b', ['a' => 'int', 'b' => 'decimal'], ['a' => 1, 'b' => '0.500000'], 'decimal', '1.500000'];
        yield 'an int lands in a decimal field' => ['old.a', ['a' => 'int'], ['a' => 4], 'decimal', '4.000000'];

        // Floats.
        yield 'float add' => ['old.a + old.b', ['a' => 'float', 'b' => 'float'], ['a' => 0.1, 'b' => 0.2], 'float', 0.30000000000000004];
        yield 'float division by zero fails' => ['old.a / 0', ['a' => 'float'], ['a' => 1.0], 'float', self::FAILS . 'division by zero'];
        yield 'a decimal widens into a float' => ['old.a + old.b', ['a' => 'decimal', 'b' => 'float'], ['a' => '1.500000', 'b' => 1.0], 'float', 2.5];

        // Comparison.
        yield 'texts compare case-sensitively' => ['old.a == "A"', ['a' => 'text'], ['a' => 'a'], 'bool', false];
        yield 'texts order by code point' => ['old.a > "z"', ['a' => 'text'], ['a' => 'é'], 'bool', true];
        yield 'a decimal compares with an int by value' => ['old.a > 1', ['a' => 'decimal'], ['a' => '1.500000'], 'bool', true];
        yield 'equal decimals' => ['old.a == old.b', ['a' => 'decimal', 'b' => 'int'], ['a' => '2.000000', 'b' => 2], 'bool', true];
        yield 'null equals null only' => ['old.a == null', ['a' => 'text?'], ['a' => null], 'bool', true];
        yield 'dates compare' => ['old.a < @2024-02-01', ['a' => 'date'], ['a' => '2024-01-31'], 'bool', true];

        // Text.
        yield 'trim removes ASCII whitespace' => ['trim(old.a)', ['a' => 'text'], ['a' => " \t x \n"], 'text', 'x'];
        yield 'trim keeps a non-breaking space' => ['trim(old.a)', ['a' => 'text'], ['a' => "\u{A0}x"], 'text', "\u{A0}x"];
        yield 'lower follows Unicode' => ['lower(old.a)', ['a' => 'text'], ['a' => 'ÄÖ'], 'text', 'äö'];
        yield 'upper can lengthen' => ['upper(old.a)', ['a' => 'text'], ['a' => 'straße'], 'text', 'STRASSE'];
        yield 'length counts characters' => ['length(old.a)', ['a' => 'text'], ['a' => 'héllo'], 'int', 5];
        yield 'substring counts characters from 0' => ['substring(old.a, 1, 3)', ['a' => 'text'], ['a' => 'héllo'], 'text', 'éll'];
        yield 'substring to the end' => ['substring(old.a, 2)', ['a' => 'text'], ['a' => 'héllo'], 'text', 'llo'];
        yield 'truncate' => ['truncate(old.a, 2)', ['a' => 'text'], ['a' => 'héllo'], 'text', 'hé'];
        yield 'replace every match' => ['replace(old.a, "a", "o")', ['a' => 'text'], ['a' => 'banana'], 'text', 'bonono'];
        yield 'contains' => ['contains(old.a, "nan")', ['a' => 'text'], ['a' => 'banana'], 'bool', true];
        yield 'startsWith' => ['startsWith(old.a, "ban")', ['a' => 'text'], ['a' => 'banana'], 'bool', true];
        yield 'split keeps empty parts' => ['split(old.a, ",")', ['a' => 'text'], ['a' => 'a,b,,c'], 'list text', ['a', 'b', '', 'c']];
        yield 'concat' => ['old.a ~ "-" ~ old.b', ['a' => 'text', 'b' => 'string'], ['a' => 'x', 'b' => 'y'], 'text', 'x-y'];
        yield 'a text fits a string' => ['old.a', ['a' => 'text'], ['a' => 'short'], 'string', 'short'];
        yield 'a text too long for a string fails' => ['old.a', ['a' => 'text'], ['a' => str_repeat('é', 256)], 'string', self::FAILS . 'doesn\'t fit a string'];

        // Numbers.
        yield 'round half up' => ['round(old.a, 2, "half-up")', ['a' => 'decimal'], ['a' => '2.345000'], 'decimal', '2.350000'];
        yield 'round half even, down' => ['round(old.a, 2, "half-even")', ['a' => 'decimal'], ['a' => '2.345000'], 'decimal', '2.340000'];
        yield 'round half even, up' => ['round(old.a, 2, "half-even")', ['a' => 'decimal'], ['a' => '2.355000'], 'decimal', '2.360000'];
        yield 'round down is toward zero' => ['round(old.a, 2, "down")', ['a' => 'decimal'], ['a' => '-2.349000'], 'decimal', '-2.340000'];
        yield 'round up is away from zero' => ['round(old.a, 2, "up")', ['a' => 'decimal'], ['a' => '2.341000'], 'decimal', '2.350000'];
        yield 'round a float half even' => ['round(old.a, 0, "half-even")', ['a' => 'float'], ['a' => 2.5], 'float', 2.0];
        yield 'round a float half up' => ['round(old.a, 0, "half-up")', ['a' => 'float'], ['a' => 2.5], 'float', 3.0];
        yield 'floor of a negative decimal' => ['floor(old.a)', ['a' => 'decimal'], ['a' => '-1.500000'], 'decimal', '-2.000000'];
        yield 'ceil of a negative decimal' => ['ceil(old.a)', ['a' => 'decimal'], ['a' => '-1.500000'], 'decimal', '-1.000000'];
        yield 'floor of a float' => ['floor(old.a)', ['a' => 'float'], ['a' => -1.5], 'float', -2.0];
        yield 'abs' => ['abs(old.a)', ['a' => 'decimal'], ['a' => '-3.500000'], 'decimal', '3.500000'];
        yield 'min widens' => ['min(old.a, old.b)', ['a' => 'int', 'b' => 'decimal'], ['a' => 1, 'b' => '2.500000'], 'decimal', '1.000000'];
        yield 'max' => ['max(old.a, old.b, 7)', ['a' => 'int', 'b' => 'int'], ['a' => 9, 'b' => 3], 'int', 9];

        // Conversions.
        yield 'toInt of a text' => ['toInt(old.a)', ['a' => 'text'], ['a' => '150'], 'int', 150];
        yield 'toInt doesn\'t trim' => ['toInt(old.a)', ['a' => 'text'], ['a' => ' 150'], 'int', self::FAILS . 'is not an int'];
        yield 'toInt refuses a fraction' => ['toInt(old.a)', ['a' => 'text'], ['a' => '1.5'], 'int', self::FAILS . 'is not an int'];
        yield 'toInt refuses past the range' => ['toInt(old.a)', ['a' => 'text'], ['a' => '99999999999999999999'], 'int', self::FAILS . 'is not an int'];
        yield 'toInt of a decimal half even' => ['toInt(old.a, "half-even")', ['a' => 'decimal'], ['a' => '2.500000'], 'int', 2];
        yield 'toInt of a float half up' => ['toInt(old.a, "half-up")', ['a' => 'float'], ['a' => 2.5], 'int', 3];
        yield 'toDecimal of a text' => ['toDecimal(old.a)', ['a' => 'text'], ['a' => '12.5'], 'decimal', '12.500000'];
        yield 'toDecimal refuses 7 places' => ['toDecimal(old.a)', ['a' => 'text'], ['a' => '1.1234567'], 'decimal', self::FAILS . 'more than 6 decimal places'];
        yield 'toDecimal of a float' => ['toDecimal(old.a)', ['a' => 'float'], ['a' => 0.1], 'decimal', '0.100000'];
        yield 'toDecimal of an int' => ['toDecimal(old.a)', ['a' => 'int'], ['a' => 5], 'decimal', '5.000000'];
        yield 'toFloat of a text with an exponent' => ['toFloat(old.a)', ['a' => 'text'], ['a' => '1e3'], 'float', 1000.0];
        yield 'toFloat refuses words' => ['toFloat(old.a)', ['a' => 'text'], ['a' => 'abc'], 'float', self::FAILS . 'is not a float'];
        yield 'toText of a decimal drops trailing zeros' => ['toText(old.a)', ['a' => 'decimal'], ['a' => '12.500000'], 'text', '12.5'];
        yield 'toText of a whole decimal' => ['toText(old.a)', ['a' => 'decimal'], ['a' => '12.000000'], 'text', '12'];
        yield 'toText of a tiny float has no exponent' => ['toText(old.a)', ['a' => 'float'], ['a' => 0.00001], 'text', '0.00001'];
        yield 'toText of a bool' => ['toText(old.a)', ['a' => 'bool'], ['a' => true], 'text', 'true'];
        yield 'toText of a date' => ['toText(old.a)', ['a' => 'date'], ['a' => '2024-01-31'], 'text', '2024-01-31'];
        yield 'try falls back on failure' => ['try(toInt(old.a), -1)', ['a' => 'text'], ['a' => 'x'], 'int', -1];
        yield 'try keeps a success' => ['try(toInt(old.a), -1)', ['a' => 'text'], ['a' => '7'], 'int', 7];
        yield 'toEnum by backing value' => ['toEnum(lower(old.a))', ['a' => 'text'], ['a' => 'USD'], 'enum t:currency', 'usd'];
        yield 'toEnum refuses an unknown value' => ['toEnum(old.a)', ['a' => 'text'], ['a' => 'gbp'], 'enum t:currency', self::FAILS . 'is no case of t:currency'];
        yield 'toDate refuses an impossible date' => ['toDate(old.a)', ['a' => 'text'], ['a' => '2024-02-30'], 'date', self::FAILS . 'is not a date'];
        yield 'toDate of a datetime' => ['toDate(old.a)', ['a' => 'datetime'], ['a' => '2024-01-31 23:59:59'], 'date', '2024-01-31'];
        yield 'toDateTime of a date' => ['toDateTime(old.a)', ['a' => 'date'], ['a' => '2024-01-31'], 'datetime', '2024-01-31 00:00:00'];
        yield 'toDateTime of a text with an offset' => ['toDateTime(old.a)', ['a' => 'text'], ['a' => '2024-01-31T10:00:00+02:00'], 'datetime', '2024-01-31 08:00:00'];
        yield 'year' => ['year(old.a)', ['a' => 'datetime'], ['a' => '2024-01-31 10:00:00'], 'int', 2024];
        yield 'month' => ['month(old.a)', ['a' => 'date'], ['a' => '2024-01-31'], 'int', 1];
        yield 'day' => ['day(old.a)', ['a' => 'date'], ['a' => '2024-01-31'], 'int', 31];

        // Lists.
        yield 'map' => ['old.a.map(x => x * 2)', ['a' => 'list int'], ['a' => [1, 2]], 'list int', [2, 4]];
        yield 'filter' => ['old.a.filter(x => x > 1)', ['a' => 'list int'], ['a' => [1, 2, 3]], 'list int', [2, 3]];
        yield 'count' => ['old.a.count()', ['a' => 'list text'], ['a' => ['x', 'y']], 'int', 2];
        yield 'join' => ['old.a.join(", ")', ['a' => 'list text'], ['a' => ['x', 'y']], 'text', 'x, y'];
        yield 'sum of decimals' => ['old.a.sum()', ['a' => 'list decimal'], ['a' => ['0.100000', '0.200000']], 'decimal', '0.300000'];
        yield 'sum of nothing' => ['old.a.sum()', ['a' => 'list int'], ['a' => []], 'int', 0];
        yield 'any' => ['old.a.any(x => x == "y")', ['a' => 'list text'], ['a' => ['x', 'y']], 'bool', true];
        yield 'first of nothing is null' => ['old.a.first()', ['a' => 'list text'], ['a' => []], 'text?', null];
        yield 'a list literal widens its items' => ['[old.a, 2.5]', ['a' => 'int'], ['a' => 1], 'list decimal', ['1.000000', '2.500000']];

        // Choices.
        yield 'match with a null arm' => ['match old.a { null => "none", "x" => "ex" else => "other" }', ['a' => 'text?'], ['a' => null], 'text', 'none'];
        yield 'an if expression' => ['if old.a > 0 then "pos" else "neg"', ['a' => 'int'], ['a' => -1], 'text', 'neg'];
        yield 'fail inside a value' => ['old.a ?? fail("missing")', ['a' => 'int?'], ['a' => null], 'int', self::FAILS . 'missing'];
    }
}
