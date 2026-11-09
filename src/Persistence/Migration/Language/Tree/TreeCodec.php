<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

use LogicException;
use stdClass;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Parser;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Printer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\SyntaxError;

/**
 * A tree to and from its stored JSON form, the one the history keeps. Each
 * node has one shape, keys in a fixed order, so the same tree always encodes
 * to the same JSON. Decoding is strict: an unknown key is an error.
 */
final class TreeCodec
{
    /** The JSON of $step. */
    public static function encodeStep(DataStep $step): string {
        return json_encode(self::step($step), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** The step $json holds. */
    public static function decodeStep(string $json): DataStep {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $node = self::object($data, '', ['data', 'steps']);

        return new DataStep(self::string($node['data'], '/data'), self::statementsOf($node['steps'], '/steps'));
    }

    /** $expr as JSON data, before encoding. */
    public static function expr(Expr $expr): mixed {
        return match (true) {
            $expr instanceof Literal => match ($expr->kind) {
                LiteralKind::Null, LiteralKind::Bool, LiteralKind::Text => $expr->value,
                default => [$expr->kind->value => $expr->value],
            },
            $expr instanceof Old => ['old' => true],
            $expr instanceof Member => $expr->safe ? ['get' => $expr->name, 'of' => self::expr($expr->of), 'safe' => true] : ['get' => $expr->name, 'of' => self::expr($expr->of)],
            $expr instanceof Variable => ['var' => $expr->name],
            $expr instanceof Name => ['name' => $expr->name],
            $expr instanceof EnumCase => ['enum' => $expr->enum, 'case' => $expr->case],
            $expr instanceof Call => ['call' => $expr->function, 'args' => array_map(self::expr(...), $expr->arguments)],
            $expr instanceof Lambda => ['lambda' => $expr->parameter, 'body' => self::expr($expr->body)],
            $expr instanceof Conditional => ['if' => self::expr($expr->condition), 'then' => self::expr($expr->then), 'else' => self::expr($expr->else)],
            $expr instanceof MatchExpression => self::matchExpression($expr),
            $expr instanceof RecordLiteral => ['record' => self::fields($expr->fields)],
            $expr instanceof NewObject => ['new' => $expr->type, 'fields' => self::fields($expr->fields)],
            $expr instanceof ListLiteral => ['list' => array_map(self::expr(...), $expr->items)],
            $expr instanceof DefaultValue => ['default' => true],
            $expr instanceof Failure => ['fail' => self::expr($expr->message)],
            $expr instanceof Attempt => ['try' => self::expr($expr->body), 'else' => self::expr($expr->fallback)],
            $expr instanceof Annotated => ['as' => Printer::type($expr->type), 'value' => self::expr($expr->expr)],
            default => throw new LogicException(sprintf('No JSON form for %s.', $expr::class)),
        };
    }

    /** The expression JSON data $data holds; $at says where, for errors. */
    public static function exprOf(mixed $data, string $at = ''): Expr {
        if ($data === null) {
            return Literal::null();
        }

        if (is_bool($data)) {
            return new Literal(LiteralKind::Bool, $data);
        }

        if (is_string($data)) {
            return Literal::text($data);
        }

        if (!is_array($data) || array_is_list($data) || $data === []) {
            throw InvalidTree::at($at, 'Expected an expression: an object, a text, a bool or null.');
        }

        $tag = self::tagOf($data, ['number', 'date', 'datetime', 'old', 'get', 'var', 'name', 'enum', 'call', 'lambda', 'if', 'match', 'record', 'new', 'list', 'default', 'fail', 'try', 'as'], $at);

        return match ($tag) {
            'number', 'date', 'datetime' => self::literal(LiteralKind::from($tag), self::object($data, $at, [$tag])[$tag], $at),
            'old' => self::marker(self::object($data, $at, ['old']), new Old(), $at),
            'get' => self::member($data, $at),
            'var' => new Variable(self::string(self::object($data, $at, ['var'])['var'], "$at/var")),
            'name' => new Name(self::string(self::object($data, $at, ['name'])['name'], "$at/name")),
            'enum' => self::enumCase($data, $at),
            'call' => self::call($data, $at),
            'lambda' => self::lambda($data, $at),
            'if' => self::conditional($data, $at),
            'match' => self::matchOf($data, $at),
            'record' => new RecordLiteral(self::fieldsOf(self::object($data, $at, ['record'])['record'], "$at/record")),
            'new' => self::newObject($data, $at),
            'list' => new ListLiteral(self::exprsOf(self::object($data, $at, ['list'])['list'], "$at/list")),
            'default' => self::marker(self::object($data, $at, ['default']), new DefaultValue(), $at),
            'fail' => new Failure(self::exprOf(self::object($data, $at, ['fail'])['fail'], "$at/fail")),
            'try' => self::attempt($data, $at),
            'as' => self::annotated($data, $at),
            default => throw InvalidTree::at($at, sprintf('Unknown expression "%s".', $tag)),
        };
    }

    /** @return array<string, mixed> */
    private static function step(DataStep $step): array {
        return ['data' => $step->type, 'steps' => array_map(self::statement(...), $step->statements)];
    }

    /** @return array<string, mixed> */
    private static function statement(Statement $statement): array {
        return match (true) {
            $statement instanceof LetStatement => ['let' => $statement->name, 'be' => self::expr($statement->value)],
            $statement instanceof SetStatement => ['set' => $statement->path, 'to' => self::expr($statement->value)],
            $statement instanceof FailStatement => ['fail' => self::expr($statement->message)],
            $statement instanceof IfStatement => $statement->else === []
                ? ['if' => self::expr($statement->condition), 'then' => array_map(self::statement(...), $statement->then)]
                : ['if' => self::expr($statement->condition), 'then' => array_map(self::statement(...), $statement->then), 'else' => array_map(self::statement(...), $statement->else)],
            default => throw new LogicException(sprintf('No JSON form for %s.', $statement::class)),
        };
    }

    /** @return list<Statement> */
    private static function statementsOf(mixed $data, string $at): array {
        if (!is_array($data) || !array_is_list($data)) {
            throw InvalidTree::at($at, 'Expected a list of statements.');
        }

        $statements = [];
        foreach ($data as $index => $item) {
            $statements[] = self::statementOf($item, "$at/$index");
        }

        return $statements;
    }

    private static function statementOf(mixed $data, string $at): Statement {
        if (!is_array($data) || array_is_list($data)) {
            throw InvalidTree::at($at, 'Expected a statement object.');
        }

        $tag = self::tagOf($data, ['let', 'set', 'fail', 'if'], $at);

        return match ($tag) {
            'let' => self::let($data, $at),
            'set' => self::set($data, $at),
            'fail' => new FailStatement(self::exprOf(self::object($data, $at, ['fail'])['fail'], "$at/fail")),
            'if' => self::ifStatement($data, $at),
            default => throw InvalidTree::at($at, sprintf('Unknown statement "%s".', $tag)),
        };
    }

    private static function let(array $data, string $at): LetStatement {
        $node = self::object($data, $at, ['let', 'be']);

        return new LetStatement(self::string($node['let'], "$at/let"), self::exprOf($node['be'], "$at/be"));
    }

    private static function set(array $data, string $at): SetStatement {
        $node = self::object($data, $at, ['set', 'to']);
        $path = $node['set'];
        if (!is_array($path) || !array_is_list($path) || $path === []) {
            throw InvalidTree::at("$at/set", 'Expected a non-empty list of field names.');
        }

        foreach ($path as $index => $name) {
            self::string($name, "$at/set/$index");
        }

        return new SetStatement($path, self::exprOf($node['to'], "$at/to"));
    }

    private static function ifStatement(array $data, string $at): IfStatement {
        $node = self::object($data, $at, ['if', 'then'], ['else']);

        return new IfStatement(
            self::exprOf($node['if'], "$at/if"),
            self::statementsOf($node['then'], "$at/then"),
            array_key_exists('else', $node) ? self::statementsOf($node['else'], "$at/else") : [],
        );
    }

    /** @return array<string, mixed> */
    private static function matchExpression(MatchExpression $match): array {
        $arms = array_map(static fn (MatchArm $arm): array => ['when' => array_map(self::expr(...), $arm->values), 'then' => self::expr($arm->result)], $match->arms);

        return $match->else === null
            ? ['match' => self::expr($match->subject), 'arms' => $arms]
            : ['match' => self::expr($match->subject), 'arms' => $arms, 'else' => self::expr($match->else)];
    }

    private static function matchOf(array $data, string $at): MatchExpression {
        $node = self::object($data, $at, ['match', 'arms'], ['else']);
        if (!is_array($node['arms']) || !array_is_list($node['arms']) || $node['arms'] === []) {
            throw InvalidTree::at("$at/arms", 'Expected a non-empty list of arms.');
        }

        $arms = [];
        foreach ($node['arms'] as $index => $arm) {
            $armAt = "$at/arms/$index";
            $armNode = self::object($arm, $armAt, ['when', 'then']);
            $values = self::exprsOf($armNode['when'], "$armAt/when");
            if ($values === []) {
                throw InvalidTree::at("$armAt/when", 'An arm compares with at least one value.');
            }

            $arms[] = new MatchArm($values, self::exprOf($armNode['then'], "$armAt/then"));
        }

        return new MatchExpression(
            self::exprOf($node['match'], "$at/match"),
            $arms,
            array_key_exists('else', $node) ? self::exprOf($node['else'], "$at/else") : null,
        );
    }

    /** An empty record encodes as {}, never []. */
    private static function fields(array $fields): array|stdClass {
        return $fields === [] ? new stdClass() : array_map(self::expr(...), $fields);
    }

    /** @return array<string, Expr> */
    private static function fieldsOf(mixed $data, string $at): array {
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw InvalidTree::at($at, 'Expected an object of fields.');
        }

        $fields = [];
        foreach ($data as $name => $value) {
            $fields[(string) $name] = self::exprOf($value, "$at/$name");
        }

        return $fields;
    }

    /** @return list<Expr> */
    private static function exprsOf(mixed $data, string $at): array {
        if (!is_array($data) || !array_is_list($data)) {
            throw InvalidTree::at($at, 'Expected a list.');
        }

        $exprs = [];
        foreach ($data as $index => $item) {
            $exprs[] = self::exprOf($item, "$at/$index");
        }

        return $exprs;
    }

    private static function literal(LiteralKind $kind, mixed $value, string $at): Literal {
        if (!is_string($value)) {
            throw InvalidTree::at("$at/{$kind->value}", 'Expected a text.');
        }

        try {
            return new Literal($kind, $value);
        } catch (\InvalidArgumentException $e) {
            throw InvalidTree::at("$at/{$kind->value}", $e->getMessage());
        }
    }

    private static function member(array $data, string $at): Member {
        $node = self::object($data, $at, ['get', 'of'], ['safe']);
        if (array_key_exists('safe', $node) && $node['safe'] !== true) {
            throw InvalidTree::at("$at/safe", 'Expected true, or no key.');
        }

        return new Member(self::exprOf($node['of'], "$at/of"), self::string($node['get'], "$at/get"), isset($node['safe']));
    }

    private static function enumCase(array $data, string $at): EnumCase {
        $node = self::object($data, $at, ['enum', 'case']);

        return new EnumCase(self::string($node['enum'], "$at/enum"), self::string($node['case'], "$at/case"));
    }

    private static function call(array $data, string $at): Call {
        $node = self::object($data, $at, ['call', 'args']);

        return new Call(self::string($node['call'], "$at/call"), self::exprsOf($node['args'], "$at/args"));
    }

    private static function lambda(array $data, string $at): Lambda {
        $node = self::object($data, $at, ['lambda', 'body']);

        return new Lambda(self::string($node['lambda'], "$at/lambda"), self::exprOf($node['body'], "$at/body"));
    }

    private static function conditional(array $data, string $at): Conditional {
        $node = self::object($data, $at, ['if', 'then', 'else']);

        return new Conditional(self::exprOf($node['if'], "$at/if"), self::exprOf($node['then'], "$at/then"), self::exprOf($node['else'], "$at/else"));
    }

    private static function newObject(array $data, string $at): NewObject {
        $node = self::object($data, $at, ['new', 'fields']);

        return new NewObject(self::string($node['new'], "$at/new"), self::fieldsOf($node['fields'], "$at/fields"));
    }

    private static function attempt(array $data, string $at): Attempt {
        $node = self::object($data, $at, ['try', 'else']);

        return new Attempt(self::exprOf($node['try'], "$at/try"), self::exprOf($node['else'], "$at/else"));
    }

    private static function annotated(array $data, string $at): Annotated {
        $node = self::object($data, $at, ['as', 'value']);
        try {
            $type = Parser::type(self::string($node['as'], "$at/as"));
        } catch (SyntaxError $e) {
            throw InvalidTree::at("$at/as", $e->getMessage());
        }

        return new Annotated(self::exprOf($node['value'], "$at/value"), $type);
    }

    /**
     * The one key of $tags that $data holds: what kind of node it is,
     * whatever order its keys come in.
     * @param list<string> $tags
     */
    private static function tagOf(array $data, array $tags, string $at): string {
        $found = array_values(array_intersect($tags, array_keys($data)));
        if (count($found) !== 1) {
            throw InvalidTree::at($at, $found === [] ? 'Unknown kind of node.' : sprintf('Ambiguous node: holds both "%s" and "%s".', $found[0], $found[1]));
        }

        return $found[0];
    }

    /** $node, a marker object such as {"old": true}, as $expr. */
    private static function marker(array $node, Expr $expr, string $at): Expr {
        if (reset($node) !== true) {
            throw InvalidTree::at($at, sprintf('Expected {"%s": true}.', array_key_first($node)));
        }

        return $expr;
    }

    /**
     * $data as an object with exactly the $required keys, and maybe the $optional ones.
     * @param list<string> $required
     * @param list<string> $optional
     * @return array<string, mixed>
     */
    private static function object(mixed $data, string $at, array $required, array $optional = []): array {
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw InvalidTree::at($at, 'Expected an object.');
        }

        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw InvalidTree::at($at, sprintf('Missing "%s".', $key));
            }
        }

        $unknown = array_diff(array_keys($data), $required, $optional);
        if ($unknown !== []) {
            throw InvalidTree::at($at, sprintf('Unknown key "%s".', reset($unknown)));
        }

        return $data;
    }

    private static function string(mixed $value, string $at): string {
        if (!is_string($value) || $value === '') {
            throw InvalidTree::at($at, 'Expected a non-empty text.');
        }

        return $value;
    }
}
