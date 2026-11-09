<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Annotated;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Attempt;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Call;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Conditional;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\DataStep;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\EnumCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Expr;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Failure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\FailStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\IfStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Lambda;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\LetStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\ListLiteral;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Literal;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\LiteralKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\MatchExpression;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Member;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Name;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\NewObject;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Old;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\RecordLiteral;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\SetStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Statement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Variable;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Type;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\TypeKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * Writes a tree back as the change language's text form, the one way: what
 * the editor shows and a diff compares. Parsing what it writes gives the same
 * tree back.
 */
final class Printer
{
    // Precedence levels, loosest first: an operand looser than its place is parenthesized.
    private const CONDITIONAL = 0;
    private const COALESCE = 1;
    private const OR = 2;
    private const AND = 3;
    private const NOT = 4;
    private const COMPARISON = 5;
    private const CONCAT = 6;
    private const ADDITIVE = 7;
    private const MULTIPLICATIVE = 8;
    private const UNARY = 9;
    private const ANNOTATED = 10;
    private const POSTFIX = 11;

    /** Built-ins written as methods on their first argument: `items.map(f)`. */
    private const METHODS = ['map', 'filter', 'first', 'count', 'join', 'sum', 'any'];

    public static function step(DataStep $step): string {
        return 'data in ' . self::typeName($step->type) . ' ' . self::block($step->statements, '') . "\n";
    }

    public static function expression(Expr $expr): string {
        return self::expr($expr, self::CONDITIONAL);
    }

    public static function type(Type $type): string {
        $text = match ($type->kind) {
            TypeKind::Scalar => match ($type->scalar) {
                ScalarType::Enum => 'enum ' . self::typeName($type->name),
                ScalarType::DateTime => 'datetime',
                default => $type->scalar->value,
            },
            TypeKind::List => 'list ' . self::type($type->item),
            TypeKind::Embed, TypeKind::Owned, TypeKind::Reference, TypeKind::TypeReference, TypeKind::Value => $type->kind->value . ' ' . self::typeName($type->name),
            default => throw new LogicException(sprintf('%s is no field type, so it has no written form.', $type->kind->value)),
        };

        return $type->nullable ? $text . '?' : $text;
    }

    /** @param list<Statement> $statements */
    private static function block(array $statements, string $indent): string {
        if ($statements === []) {
            return '{}';
        }

        $lines = array_map(static fn (Statement $statement): string => $indent . '  ' . self::statement($statement, $indent . '  '), $statements);

        return "{\n" . implode("\n", $lines) . "\n" . $indent . '}';
    }

    private static function statement(Statement $statement, string $indent): string {
        return match (true) {
            $statement instanceof LetStatement => 'let ' . self::variable($statement->name) . ' = ' . self::expression($statement->value),
            $statement instanceof SetStatement => 'set new.' . implode('.', array_map(self::field(...), $statement->path)) . ' = ' . self::expression($statement->value),
            $statement instanceof FailStatement => 'fail(' . self::expression($statement->message) . ')',
            $statement instanceof IfStatement => self::ifStatement($statement, $indent),
            default => throw new LogicException(sprintf('No written form for %s.', $statement::class)),
        };
    }

    private static function ifStatement(IfStatement $statement, string $indent): string {
        $text = 'if ' . self::expression($statement->condition) . ' ' . self::block($statement->then, $indent);
        if ($statement->else === []) {
            return $text;
        }

        // else if, when the else holds one if and nothing more.
        if (count($statement->else) === 1 && $statement->else[0] instanceof IfStatement) {
            return $text . ' else ' . self::ifStatement($statement->else[0], $indent);
        }

        return $text . ' else ' . self::block($statement->else, $indent);
    }

    /** $expr, parenthesized when it binds looser than $level. */
    private static function expr(Expr $expr, int $level): string {
        [$text, $own] = self::written($expr);

        return $own < $level ? '(' . $text . ')' : $text;
    }

    /**
     * $expr's text and its own precedence level.
     * @return array{string, int}
     */
    private static function written(Expr $expr): array {
        return match (true) {
            $expr instanceof Literal => [self::literal($expr), self::POSTFIX],
            $expr instanceof Old => ['old', self::POSTFIX],
            $expr instanceof DefaultValue => ['default', self::POSTFIX],
            $expr instanceof Variable => [self::variable($expr->name), self::POSTFIX],
            $expr instanceof Name => [self::variable($expr->name), self::POSTFIX],
            $expr instanceof EnumCase => [self::variable($expr->case), self::POSTFIX],
            $expr instanceof Member => [self::expr($expr->of, self::POSTFIX) . ($expr->safe ? '?.' : '.') . self::field($expr->name), self::POSTFIX],
            $expr instanceof Call => self::call($expr),
            $expr instanceof Lambda => [self::variable($expr->parameter) . ' => ' . self::expression($expr->body), self::CONDITIONAL],
            $expr instanceof Conditional => ['if ' . self::expression($expr->condition) . ' then ' . self::expression($expr->then) . ' else ' . self::expression($expr->else), self::CONDITIONAL],
            $expr instanceof MatchExpression => [self::matchExpression($expr), self::POSTFIX],
            $expr instanceof RecordLiteral => [self::fields($expr->fields), self::POSTFIX],
            $expr instanceof NewObject => ['new ' . self::typeName($expr->type) . ' ' . self::fields($expr->fields), self::POSTFIX],
            $expr instanceof ListLiteral => ['[' . implode(', ', array_map(self::expression(...), $expr->items)) . ']', self::POSTFIX],
            $expr instanceof Failure => ['fail(' . self::expression($expr->message) . ')', self::POSTFIX],
            $expr instanceof Attempt => ['try(' . self::expression($expr->body) . ', ' . self::expression($expr->fallback) . ')', self::POSTFIX],
            $expr instanceof Annotated => [self::expr($expr->expr, self::POSTFIX) . ' as ' . self::type($expr->type), self::ANNOTATED],
            default => throw new LogicException(sprintf('No written form for %s.', $expr::class)),
        };
    }

    /** @return array{string, int} */
    private static function call(Call $call): array {
        $args = $call->arguments;
        $infix = static fn (string $symbol, int $level, int $leftLevel, int $rightLevel): array => [self::expr($args[0], $leftLevel) . ' ' . $symbol . ' ' . self::expr($args[1], $rightLevel), $level];

        $binary = count($args) === 2;
        $comparison = array_search($call->function, Parser::COMPARISONS, true);
        $additive = array_search($call->function, Parser::ADDITIVE, true);
        $multiplicative = array_search($call->function, Parser::MULTIPLICATIVE, true);

        return match (true) {
            $binary && $call->function === 'coalesce' => $infix('??', self::COALESCE, self::COALESCE + 1, self::COALESCE),
            $binary && $call->function === 'or' => $infix('or', self::OR, self::OR, self::OR + 1),
            $binary && $call->function === 'and' => $infix('and', self::AND, self::AND, self::AND + 1),
            count($args) === 1 && $call->function === 'not' => ['not ' . self::expr($args[0], self::NOT), self::NOT],
            $binary && $comparison !== false => $infix($comparison, self::COMPARISON, self::COMPARISON + 1, self::COMPARISON + 1),
            $binary && $call->function === 'concat' => $infix('~', self::CONCAT, self::CONCAT, self::CONCAT + 1),
            $binary && $additive !== false => $infix($additive, self::ADDITIVE, self::ADDITIVE, self::ADDITIVE + 1),
            $binary && $multiplicative !== false => $infix($multiplicative, self::MULTIPLICATIVE, self::MULTIPLICATIVE, self::MULTIPLICATIVE + 1),
            count($args) === 1 && $call->function === 'neg' => ['-' . self::expr($args[0], self::UNARY), self::UNARY],
            $args !== [] && in_array($call->function, self::METHODS, true) => [
                self::expr($args[0], self::POSTFIX) . '.' . self::field($call->function) . '(' . implode(', ', array_map(self::expression(...), array_slice($args, 1))) . ')',
                self::POSTFIX,
            ],
            default => [self::variable($call->function) . '(' . implode(', ', array_map(self::expression(...), $args)) . ')', self::POSTFIX],
        };
    }

    private static function matchExpression(MatchExpression $match): string {
        $arms = [];
        foreach ($match->arms as $arm) {
            $arms[] = implode(', ', array_map(self::expression(...), $arm->values)) . ' => ' . self::expression($arm->result);
        }
        if ($match->else !== null) {
            $arms[] = 'else => ' . self::expression($match->else);
        }

        return 'match ' . self::expression($match->subject) . ' { ' . implode(', ', $arms) . ' }';
    }

    /** @param array<string, Expr> $fields */
    private static function fields(array $fields): string {
        if ($fields === []) {
            return '{}';
        }

        $written = [];
        foreach ($fields as $name => $value) {
            $written[] = self::field((string) $name) . ': ' . self::expression($value);
        }

        return '{ ' . implode(', ', $written) . ' }';
    }

    private static function literal(Literal $literal): string {
        return match ($literal->kind) {
            LiteralKind::Null => 'null',
            LiteralKind::Bool => $literal->value ? 'true' : 'false',
            LiteralKind::Number => $literal->value,
            LiteralKind::Text => self::text($literal->value),
            LiteralKind::Date => '@' . $literal->value,
            LiteralKind::DateTime => '@' . str_replace(' ', 'T', $literal->value) . 'Z',
        };
    }

    /** A text in quotes: quotes, backslashes and control characters escaped. */
    private static function text(string $text): string {
        $escaped = preg_replace_callback('/["\\\\\x00-\x1F\x7F]/', static fn (array $match): string => match ($match[0]) {
            '"' => '\\"',
            '\\' => '\\\\',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            default => sprintf('\\u{%X}', ord($match[0])),
        }, $text);

        return '"' . $escaped . '"';
    }

    /** A field's name: bare when it's a plain word, any word included. */
    private static function field(string $name): string {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : self::quoted($name);
    }

    /** A variable's name: bare when it's a plain word and no keyword. */
    private static function variable(string $name): string {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 && !in_array($name, Parser::KEYWORDS, true) ? $name : self::quoted($name);
    }

    private static function typeName(string $name): string {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\-]*:[A-Za-z0-9_][A-Za-z0-9_.\-]*$/', $name) === 1 ? $name : self::quoted($name);
    }

    private static function quoted(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
