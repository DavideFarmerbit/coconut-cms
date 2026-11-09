<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins;

use Closure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php\Runtime;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Call;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Expr;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Literal;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\LiteralKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Type;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\TypeKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\TypeProblem;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\TypeRules;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * The built-ins a step can call, by name: operators included, since `a + b`
 * is `add(a, b)`. standard() is every one the language has; a new one is a
 * change to the language, never per project.
 */
final readonly class Builtins
{
    /** How generated PHP reaches Runtime. */
    private const R = '\\' . Runtime::class . '::';

    /** @param array<string, Builtin> $builtins */
    private function __construct(
        private array $builtins,
    ) {
    }

    public function get(string $name): ?Builtin {
        return $this->builtins[$name] ?? null;
    }

    public static function standard(): self {
        $all = [
            ...self::arithmetic(),
            ...self::comparisons(),
            ...self::logic(),
            ...self::texts(),
            ...self::numbers(),
            ...self::conversions(),
            ...self::dates(),
            ...self::lists(),
        ];

        $byName = [];
        foreach ($all as $builtin) {
            $byName[$builtin->name] = $builtin;
        }

        return new self($byName);
    }

    /** @return list<Builtin> */
    private static function arithmetic(): array {
        $binary = static function (string $name, string $symbol, bool $divides = false): Builtin {
            return new Builtin(
                $name,
                "number $symbol number",
                static function (array $types) use ($symbol, $divides): Signature {
                    self::number($types[0], "the left of $symbol");
                    self::number($types[1], "the right of $symbol");
                    $type = TypeRules::widerNumber($types[0], $types[1]);
                    // An int divided by an int is exact: a decimal.
                    if ($divides && $type->is(ScalarType::Int)) {
                        $type = Type::decimal();
                    }

                    return new Signature($type, [$type, $type]);
                },
                static fn (array $code, array $types, Type $result): string => self::R . self::prefix($result) . ucfirst($name) . "($code[0], $code[1])",
                sharesType: true,
            );
        };

        return [
            $binary('add', '+'),
            $binary('sub', '-'),
            $binary('mul', '*'),
            $binary('div', '/', true),
            $binary('mod', '%'),
            new Builtin(
                'neg',
                '-number',
                static function (array $types): Signature {
                    self::number($types[0], 'a negated value');

                    return new Signature($types[0], [$types[0]]);
                },
                static fn (array $code, array $types, Type $result): string => $result->is(ScalarType::Float) ? "(-$code[0])" : self::R . self::prefix($result) . "Neg($code[0])",
            ),
            new Builtin(
                'concat',
                'text ~ text',
                static function (array $types): Signature {
                    self::text($types[0], 'the left of ~');
                    self::text($types[1], 'the right of ~');

                    return new Signature(Type::text(), [Type::text(), Type::text()]);
                },
                static fn (array $code): string => "($code[0] . $code[1])",
            ),
        ];
    }

    /** @return list<Builtin> */
    private static function comparisons(): array {
        $equality = static function (string $name, string $operator): Builtin {
            return new Builtin(
                $name,
                "a $operator b",
                static function (array $types) use ($operator): Signature {
                    $type = self::comparable($types[0], $types[1], $operator);

                    return new Signature(Type::bool(), [$type, $type]);
                },
                static fn (array $code): string => '(' . $code[0] . ($name === 'eq' ? ' === ' : ' !== ') . $code[1] . ')',
                sharesType: true,
            );
        };

        $order = static function (string $name, string $operator): Builtin {
            return new Builtin(
                $name,
                "a $operator b",
                static function (array $types) use ($operator): Signature {
                    foreach ($types as $type) {
                        self::nonNull($type, "a value compared with $operator");
                        if (!$type->isNumber() && !$type->isText() && !$type->is(ScalarType::Date, ScalarType::DateTime)) {
                            throw new TypeProblem(sprintf('%s compares numbers, texts and dates, not %s.', $operator, $type->describe()));
                        }
                    }

                    $type = self::comparable($types[0], $types[1], $operator);

                    return new Signature(Type::bool(), [$type, $type]);
                },
                static fn (array $code, array $types): string => '(' . self::R . "compare($code[0], $code[1], '" . TypeRules::runtimeName($types[0]) . "') $operator 0)",
                sharesType: true,
            );
        };

        return [
            $equality('eq', '=='),
            $equality('ne', '!='),
            $order('lt', '<'),
            $order('le', '<='),
            $order('gt', '>'),
            $order('ge', '>='),
        ];
    }

    /** @return list<Builtin> */
    private static function logic(): array {
        $binary = static fn (string $name, string $operator): Builtin => new Builtin(
            $name,
            "bool $name bool",
            static function (array $types) use ($name): Signature {
                self::bool($types[0], "the left of $name");
                self::bool($types[1], "the right of $name");

                return new Signature(Type::bool(), [Type::bool(), Type::bool()]);
            },
            static fn (array $code): string => "($code[0] $operator $code[1])",
        );

        return [
            $binary('and', '&&'),
            $binary('or', '||'),
            new Builtin(
                'not',
                'not bool',
                static function (array $types): Signature {
                    self::bool($types[0], 'what not negates');

                    return new Signature(Type::bool(), [Type::bool()]);
                },
                static fn (array $code): string => "(!$code[0])",
            ),
            new Builtin(
                'coalesce',
                'a ?? b',
                static function (array $types): Signature {
                    if (!$types[0]->nullable && $types[0]->kind !== TypeKind::Null) {
                        throw new TypeProblem(sprintf('The left of ?? is never null (%s): ?? isn\'t needed.', $types[0]->describe()));
                    }

                    $type = TypeRules::unify($types[0]->nonNull(), $types[1]) ?? throw new TypeProblem(sprintf('?? gives %s or %s: they don\'t meet.', $types[0]->nonNull()->describe(), $types[1]->describe()));

                    return new Signature($type, [$type->orNull(), $type]);
                },
                static fn (array $code): string => "($code[0] ?? $code[1])",
                sharesType: true,
            ),
        ];
    }

    /** @return list<Builtin> */
    private static function texts(): array {
        $textToText = static fn (string $name) => new Builtin(
            $name,
            "$name(text)",
            static function (array $types) use ($name): Signature {
                self::text($types[0], "$name's text");

                return new Signature(Type::text(), [Type::text()]);
            },
            self::runtime($name),
        );

        return [
            $textToText('trim'),
            $textToText('lower'),
            $textToText('upper'),
            new Builtin('length', 'length(text)', self::fixed([Type::text()], Type::int()), self::runtime('length')),
            new Builtin(
                'substring',
                'substring(text, start, length?)',
                static function (array $types): Signature {
                    if (count($types) !== 2 && count($types) !== 3) {
                        throw new TypeProblem('substring takes a text, a start and maybe a length.');
                    }

                    self::text($types[0], 'substring\'s text');
                    self::int($types[1], 'substring\'s start');
                    if (isset($types[2])) {
                        self::int($types[2], 'substring\'s length');
                    }

                    return new Signature(Type::text(), array_slice([Type::text(), Type::int(), Type::int()], 0, count($types)));
                },
                self::runtime('substring'),
            ),
            new Builtin('truncate', 'truncate(text, length)', self::fixed([Type::text(), Type::int()], Type::text()), self::runtime('truncate')),
            new Builtin('replace', 'replace(text, search, replacement)', self::fixed([Type::text(), Type::text(), Type::text()], Type::text()), self::runtime('replace')),
            new Builtin('contains', 'contains(text, part)', self::fixed([Type::text(), Type::text()], Type::bool()), static fn (array $code): string => "str_contains($code[0], $code[1])"),
            new Builtin('startsWith', 'startsWith(text, start)', self::fixed([Type::text(), Type::text()], Type::bool()), static fn (array $code): string => "str_starts_with($code[0], $code[1])"),
            // PHP only: SQL has no portable split. A step using it runs as a column job.
            new Builtin('split', 'split(text, separator)', self::fixed([Type::text(), Type::text()], Type::list(Type::text())), self::runtime('split')),
        ];
    }

    /** @return list<Builtin> */
    private static function numbers(): array {
        $sameNumber = static fn (string $name, bool $integral = true) => new Builtin(
            $name,
            "$name(number)",
            static function (array $types) use ($name, $integral): Signature {
                self::number($types[0], "$name's number", count($types));
                if (!$integral && $types[0]->is(ScalarType::Int)) {
                    throw new TypeProblem(sprintf('%s of an int is the int itself.', $name));
                }

                return new Signature($types[0], [$types[0]]);
            },
            self::runtime($name),
        );

        $extreme = static fn (string $name) => new Builtin(
            $name,
            "$name(number, number, …)",
            static function (array $types) use ($name): Signature {
                if (count($types) < 2) {
                    throw new TypeProblem(sprintf('%s takes two numbers or more.', $name));
                }

                $type = $types[0];
                foreach ($types as $at => $argument) {
                    self::number($argument, sprintf('%s\'s value %d', $name, $at + 1));
                    $type = TypeRules::widerNumber($type, $argument);
                }

                return new Signature($type, array_fill(0, count($types), $type));
            },
            static fn (array $code, array $types, Type $result): string => self::R . "$name([" . implode(', ', $code) . "], '" . TypeRules::runtimeName($result) . "')",
            sharesType: true,
        );

        return [
            $sameNumber('abs'),
            $sameNumber('floor', false),
            $sameNumber('ceil', false),
            $extreme('min'),
            $extreme('max'),
            new Builtin(
                'round',
                'round(number, places, mode)',
                static function (array $types, array $args): Signature {
                    if (count($types) !== 3) {
                        throw new TypeProblem('round takes a number, its places and a rounding mode: round(price, 2, "half-up").');
                    }

                    self::number($types[0], 'round\'s number');
                    self::places($args[1]);
                    self::mode($args[2]);

                    return new Signature($types[0], [$types[0], null, null]);
                },
                static fn (array $code, array $types, Type $result, array $args = []): string => self::R . 'round(' . $code[0] . ', ' . $code[1] . ', ' . $code[2] . ')',
            ),
        ];
    }

    /** @return list<Builtin> */
    private static function conversions(): array {
        return [
            new Builtin(
                'toInt',
                'toInt(text), toInt(number, mode)',
                static function (array $types, array $args): Signature {
                    self::nonNull($types[0], 'toInt\'s value');
                    if ($types[0]->isText() || $types[0]->is(ScalarType::Int)) {
                        self::count($types, 1, 'toInt of a text or an int takes nothing more.');

                        return new Signature(Type::int(), [$types[0]->isText() ? Type::text() : Type::int()]);
                    }

                    if (!$types[0]->is(ScalarType::Decimal, ScalarType::Float)) {
                        throw new TypeProblem(sprintf('toInt takes a text or a number, not %s.', $types[0]->describe()));
                    }

                    self::count($types, 2, 'toInt of a decimal or a float rounds: give the mode, toInt(price, "half-up").');
                    self::mode($args[1]);

                    return new Signature(Type::int(), [$types[0], null]);
                },
                static fn (array $code, array $types): string => match (true) {
                    $types[0]->isText() => self::R . "intFromText($code[0])",
                    $types[0]->is(ScalarType::Int) => $code[0],
                    default => self::R . "intFromNumber($code[0], $code[1])",
                },
            ),
            new Builtin(
                'toDecimal',
                'toDecimal(text or number)',
                static function (array $types): Signature {
                    self::count($types, 1, 'toDecimal takes one value.');
                    self::nonNull($types[0], 'toDecimal\'s value');
                    if (!$types[0]->isText() && !$types[0]->isNumber()) {
                        throw new TypeProblem(sprintf('toDecimal takes a text or a number, not %s.', $types[0]->describe()));
                    }

                    return new Signature(Type::decimal(), [$types[0]->isText() ? Type::text() : $types[0]]);
                },
                static fn (array $code, array $types): string => match (true) {
                    $types[0]->isText() => self::R . "decimalFromText($code[0])",
                    $types[0]->is(ScalarType::Float) => self::R . "decimalFromFloat($code[0])",
                    default => self::R . "decimalOf($code[0])",
                },
            ),
            new Builtin(
                'toFloat',
                'toFloat(text or number)',
                static function (array $types): Signature {
                    self::count($types, 1, 'toFloat takes one value.');
                    self::nonNull($types[0], 'toFloat\'s value');
                    if (!$types[0]->isText() && !$types[0]->isNumber()) {
                        throw new TypeProblem(sprintf('toFloat takes a text or a number, not %s.', $types[0]->describe()));
                    }

                    return new Signature(Type::float(), [$types[0]->isText() ? Type::text() : $types[0]]);
                },
                static fn (array $code, array $types): string => $types[0]->isText() ? self::R . "floatFromText($code[0])" : "((float) $code[0])",
            ),
            new Builtin(
                'toText',
                'toText(scalar)',
                static function (array $types): Signature {
                    self::count($types, 1, 'toText takes one value.');
                    self::nonNull($types[0], 'toText\'s value');
                    if ($types[0]->kind !== TypeKind::Scalar) {
                        throw new TypeProblem(sprintf('toText takes a scalar, not %s.', $types[0]->describe()));
                    }

                    return new Signature(Type::text(), [$types[0]]);
                },
                static fn (array $code, array $types): string => self::R . "textOf($code[0], '" . TypeRules::runtimeName($types[0]) . "')",
            ),
            new Builtin(
                'toDate',
                'toDate(text or datetime)',
                static function (array $types): Signature {
                    self::count($types, 1, 'toDate takes one value.');
                    self::nonNull($types[0], 'toDate\'s value');
                    if (!$types[0]->isText() && !$types[0]->is(ScalarType::DateTime, ScalarType::Date)) {
                        throw new TypeProblem(sprintf('toDate takes a text or a datetime, not %s.', $types[0]->describe()));
                    }

                    return new Signature(Type::scalar(ScalarType::Date), [$types[0]]);
                },
                static fn (array $code, array $types): string => match (true) {
                    $types[0]->isText() => self::R . "dateFromText($code[0])",
                    $types[0]->is(ScalarType::DateTime) => "substr($code[0], 0, 10)",
                    default => $code[0],
                },
            ),
            new Builtin(
                'toDateTime',
                'toDateTime(text or date)',
                static function (array $types): Signature {
                    self::count($types, 1, 'toDateTime takes one value.');
                    self::nonNull($types[0], 'toDateTime\'s value');
                    if (!$types[0]->isText() && !$types[0]->is(ScalarType::DateTime, ScalarType::Date)) {
                        throw new TypeProblem(sprintf('toDateTime takes a text or a date, not %s.', $types[0]->describe()));
                    }

                    return new Signature(Type::scalar(ScalarType::DateTime), [$types[0]]);
                },
                static fn (array $code, array $types): string => match (true) {
                    $types[0]->isText() => self::R . "dateTimeFromText($code[0])",
                    $types[0]->is(ScalarType::Date) => "($code[0] . ' 00:00:00')",
                    default => $code[0],
                },
            ),
        ];
    }

    /** @return list<Builtin> */
    private static function dates(): array {
        $part = static fn (string $name) => new Builtin(
            $name,
            "$name(date)",
            static function (array $types) use ($name): Signature {
                self::count($types, 1, "$name takes one date.");
                self::nonNull($types[0], "$name's date");
                if (!$types[0]->is(ScalarType::Date, ScalarType::DateTime)) {
                    throw new TypeProblem(sprintf('%s takes a date or a datetime, not %s.', $name, $types[0]->describe()));
                }

                return new Signature(Type::int(), [$types[0]]);
            },
            self::runtime($name),
        );

        return [$part('year'), $part('month'), $part('day')];
    }

    /** @return list<Builtin> */
    private static function lists(): array {
        return [
            new Builtin(
                'map',
                'list.map(item => value)',
                static function (array $types): Signature {
                    self::list($types[0], 'map');

                    return new Signature(Type::list($types[1]), [$types[0], null]);
                },
                static fn (array $code): string => "array_map($code[1], $code[0])",
                lambda: true,
            ),
            new Builtin(
                'filter',
                'list.filter(item => bool)',
                static function (array $types): Signature {
                    self::list($types[0], 'filter');
                    self::bool($types[1], 'what filter keeps');

                    return new Signature($types[0], [$types[0], null]);
                },
                static fn (array $code): string => "array_values(array_filter($code[0], $code[1]))",
                lambda: true,
            ),
            new Builtin(
                'any',
                'list.any(item => bool)',
                static function (array $types): Signature {
                    self::list($types[0], 'any');
                    self::bool($types[1], 'what any tests');

                    return new Signature(Type::bool(), [$types[0], null]);
                },
                static fn (array $code): string => self::R . "any($code[0], $code[1])",
                lambda: true,
            ),
            new Builtin(
                'first',
                'list.first()',
                static function (array $types): Signature {
                    self::count($types, 1, 'first takes nothing more.');
                    self::list($types[0], 'first');

                    return new Signature($types[0]->item->orNull(), [$types[0]]);
                },
                static fn (array $code): string => "($code[0][0] ?? null)",
            ),
            new Builtin(
                'count',
                'list.count()',
                static function (array $types): Signature {
                    self::count($types, 1, 'count takes nothing more.');
                    self::list($types[0], 'count');

                    return new Signature(Type::int(), [$types[0]]);
                },
                static fn (array $code): string => "count($code[0])",
            ),
            new Builtin(
                'join',
                'list.join(separator)',
                static function (array $types): Signature {
                    self::count($types, 2, 'join takes a separator.');
                    self::list($types[0], 'join');
                    self::text($types[0]->item, 'what join joins');
                    self::text($types[1], 'join\'s separator');

                    return new Signature(Type::text(), [Type::list(Type::text()), Type::text()]);
                },
                static fn (array $code): string => "implode($code[1], $code[0])",
            ),
            new Builtin(
                'sum',
                'list.sum()',
                static function (array $types): Signature {
                    self::count($types, 1, 'sum takes nothing more.');
                    self::list($types[0], 'sum');
                    self::number($types[0]->item, 'what sum adds');

                    return new Signature($types[0]->item, [$types[0]]);
                },
                static fn (array $code, array $types, Type $result): string => self::R . "sum($code[0], '" . TypeRules::runtimeName($result) . "')",
            ),
        ];
    }

    /**
     * A check taking exactly $parameters, each non-null, giving $result.
     * @param list<Type> $parameters
     */
    private static function fixed(array $parameters, Type $result): Closure {
        return static function (array $types) use ($parameters, $result): Signature {
            self::count($types, count($parameters), sprintf('It takes %d values.', count($parameters)));
            foreach ($parameters as $at => $parameter) {
                self::nonNull($types[$at], sprintf('value %d', $at + 1));
                $fits = $parameter->isText() ? $types[$at]->isText() : $types[$at]->nonNull()->equals($parameter);
                if (!$fits) {
                    throw new TypeProblem(sprintf('Value %d must be %s, not %s.', $at + 1, $parameter->describe(), $types[$at]->describe()));
                }
            }

            return new Signature($result, $parameters);
        };
    }

    /** PHP calling Runtime::$method with every argument. */
    private static function runtime(string $method): Closure {
        return static fn (array $code): string => self::R . $method . '(' . implode(', ', $code) . ')';
    }

    /** The prefix of Runtime's arithmetic for $type: intAdd, decAdd, floatAdd. */
    private static function prefix(Type $type): string {
        return match ($type->scalar) {
            ScalarType::Int => 'int',
            ScalarType::Decimal => 'dec',
            default => 'float',
        };
    }

    /** The one type two compared values are compared as. */
    private static function comparable(Type $a, Type $b, string $operator): Type {
        if ($a->kind === TypeKind::Null || $b->kind === TypeKind::Null) {
            $other = $a->kind === TypeKind::Null ? $b : $a;
            if (!$other->nullable && $other->kind !== TypeKind::Null) {
                throw new TypeProblem(sprintf('%s is never null: comparing it with null is always %s.', $other->describe(), $operator === '!=' ? 'true' : 'false'));
            }

            return $other;
        }

        $type = match (true) {
            $a->isNumber() && $b->isNumber() => TypeRules::widerNumber($a, $b),
            $a->isText() && $b->isText() => Type::text(),
            $a->kind === TypeKind::Scalar && $a->nonNull()->equals($b->nonNull()) => $a->nonNull(),
            in_array($a->kind, [TypeKind::Reference, TypeKind::TypeReference], true) && $a->kind === $b->kind => $a->nonNull(),
            default => throw new TypeProblem(sprintf('%s and %s can\'t be compared with %s.', $a->describe(), $b->describe(), $operator)),
        };

        return $a->nullable || $b->nullable ? $type->orNull() : $type;
    }

    private static function nonNull(Type $type, string $what): void {
        if ($type->nullable || $type->kind === TypeKind::Null) {
            throw new TypeProblem(sprintf('%s may be null (%s): handle it first, with ?? or a check.', ucfirst($what), $type->describe()));
        }
    }

    private static function number(Type $type, string $what, int $count = 1): void {
        if ($count !== 1) {
            throw new TypeProblem('It takes one number.');
        }

        self::nonNull($type, $what);
        if (!$type->isNumber()) {
            throw new TypeProblem(sprintf('%s must be a number, not %s.', ucfirst($what), $type->describe()));
        }
    }

    private static function int(Type $type, string $what): void {
        self::nonNull($type, $what);
        if (!$type->is(ScalarType::Int)) {
            throw new TypeProblem(sprintf('%s must be an int, not %s.', ucfirst($what), $type->describe()));
        }
    }

    private static function text(Type $type, string $what): void {
        self::nonNull($type, $what);
        if (!$type->isText()) {
            throw new TypeProblem(sprintf('%s must be a text, not %s. toText() turns a value into one.', ucfirst($what), $type->describe()));
        }
    }

    private static function bool(Type $type, string $what): void {
        self::nonNull($type, $what);
        if (!$type->is(ScalarType::Bool)) {
            throw new TypeProblem(sprintf('%s must be a bool, not %s.', ucfirst($what), $type->describe()));
        }
    }

    private static function list(Type $type, string $function): void {
        if ($type->kind !== TypeKind::List) {
            throw new TypeProblem(sprintf('%s runs on a list, not %s.', $function, $type->describe()));
        }
    }

    /** @param list<Type> $types */
    private static function count(array $types, int $expected, string $message): void {
        if (count($types) !== $expected) {
            throw new TypeProblem($message);
        }
    }

    /** A number of places written as is: 0 to DECIMAL_SCALE. */
    private static function places(Expr $places): void {
        if (!$places instanceof Literal || $places->kind !== LiteralKind::Number || !ctype_digit($places->value) || (int) $places->value > 6) {
            throw new TypeProblem('The places to round to are written as a number from 0 to 6.');
        }
    }

    /** A rounding mode written as is: "half-up", "half-even", "down" or "up". */
    private static function mode(Expr $mode): void {
        if (!$mode instanceof Literal || $mode->kind !== LiteralKind::Text || !in_array($mode->value, Runtime::MODES, true)) {
            throw new TypeProblem(sprintf('The rounding mode is written as one of "%s".', implode('", "', Runtime::MODES)));
        }
    }
}
