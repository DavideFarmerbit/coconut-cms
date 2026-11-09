<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax;

use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Annotated;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Attempt;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Call;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Conditional;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\DataStep;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Expr;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Failure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\FailStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\IfStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Lambda;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\LetStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\ListLiteral;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Literal;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\LiteralKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\MatchArm;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\MatchExpression;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Member;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Name;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\NewObject;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Old;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\RecordLiteral;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\SetStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Statement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Type;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * Reads the change language's text form into its tree. Names are resolved
 * later: a bare name is a Name until the checker says whether it's a
 * variable or an enum case.
 */
final class Parser
{
    /** Words that can't name a variable unless quoted. Any word names a field. */
    public const KEYWORDS = ['if', 'then', 'else', 'match', 'new', 'old', 'true', 'false', 'null', 'default', 'fail', 'try', 'as', 'and', 'or', 'not', 'let', 'set', 'data', 'in'];

    /** Comparison symbols, and the built-in each one calls. */
    public const COMPARISONS = ['==' => 'eq', '!=' => 'ne', '<' => 'lt', '<=' => 'le', '>' => 'gt', '>=' => 'ge'];

    /** Arithmetic symbols, by precedence level, and the built-in each one calls. */
    public const ADDITIVE = ['+' => 'add', '-' => 'sub'];
    public const MULTIPLICATIVE = ['*' => 'mul', '/' => 'div', '%' => 'mod'];

    private Lexer $lexer;

    private function __construct(string $text) {
        $this->lexer = new Lexer($text);
    }

    /** A whole `data in … { … }` step. */
    public static function step(string $text): DataStep {
        $parser = new self($text);
        $step = $parser->dataStep();
        $parser->expectEnd();

        return $step;
    }

    /** One expression. */
    public static function expression(string $text): Expr {
        $parser = new self($text);
        $expr = $parser->expr();
        $parser->expectEnd();

        return $expr;
    }

    /** One type: `decimal`, `enum shop:currency?`, `list owns shop:wheel`. */
    public static function type(string $text): Type {
        $parser = new self($text);
        $type = $parser->typeSyntax();
        $parser->expectEnd();

        return $type;
    }

    private function dataStep(): DataStep {
        $this->expectKeyword('data');
        $this->expectKeyword('in');
        $type = $this->lexer->typeName();

        return new DataStep($type, $this->block());
    }

    /** @return list<Statement> */
    private function block(): array {
        $this->expectSymbol('{');
        $statements = [];
        while (!$this->lexer->peek()->isSymbol('}')) {
            $statements[] = $this->statement();
        }
        $this->lexer->next();

        return $statements;
    }

    private function statement(): Statement {
        $token = $this->lexer->peek();
        if ($token->isKeyword('let')) {
            $this->lexer->next();
            $name = $this->variableName();
            $this->expectSymbol('=');

            return new LetStatement($name, $this->expr());
        }

        if ($token->isKeyword('set')) {
            $this->lexer->next();
            $this->expectKeyword('new');
            $path = [];
            do {
                $this->expectSymbol('.');
                $path[] = $this->fieldName();
            } while ($this->lexer->peek()->isSymbol('.'));
            $this->expectSymbol('=');

            return new SetStatement($path, $this->expr());
        }

        if ($token->isKeyword('if')) {
            return $this->ifStatement();
        }

        if ($token->isKeyword('fail')) {
            $this->lexer->next();

            return new FailStatement($this->parenthesized());
        }

        throw $this->unexpected($token, 'a statement: let, set, if or fail');
    }

    private function ifStatement(): IfStatement {
        $this->expectKeyword('if');
        $condition = $this->expr();
        $then = $this->block();
        if (!$this->lexer->peek()->isKeyword('else')) {
            return new IfStatement($condition, $then);
        }

        $this->lexer->next();
        $else = $this->lexer->peek()->isKeyword('if') ? [$this->ifStatement()] : $this->block();

        return new IfStatement($condition, $then, $else);
    }

    private function expr(): Expr {
        if ($this->lexer->peek()->isKeyword('if')) {
            $this->lexer->next();
            $condition = $this->expr();
            $this->expectKeyword('then');
            $then = $this->expr();
            $this->expectKeyword('else');

            return new Conditional($condition, $then, $this->expr());
        }

        return $this->coalesce();
    }

    private function coalesce(): Expr {
        $left = $this->disjunction();
        if (!$this->lexer->peek()->isSymbol('??')) {
            return $left;
        }

        $this->lexer->next();

        return new Call('coalesce', [$left, $this->coalesce()]);
    }

    private function disjunction(): Expr {
        $left = $this->conjunction();
        while ($this->lexer->peek()->isKeyword('or')) {
            $this->lexer->next();
            $left = new Call('or', [$left, $this->conjunction()]);
        }

        return $left;
    }

    private function conjunction(): Expr {
        $left = $this->negation();
        while ($this->lexer->peek()->isKeyword('and')) {
            $this->lexer->next();
            $left = new Call('and', [$left, $this->negation()]);
        }

        return $left;
    }

    private function negation(): Expr {
        if ($this->lexer->peek()->isKeyword('not')) {
            $this->lexer->next();

            return new Call('not', [$this->negation()]);
        }

        return $this->comparison();
    }

    private function comparison(): Expr {
        $left = $this->concatenation();
        $token = $this->lexer->peek();
        if ($token->kind !== TokenKind::Symbol || !isset(self::COMPARISONS[$token->text])) {
            return $left;
        }

        $this->lexer->next();
        $right = $this->concatenation();
        $next = $this->lexer->peek();
        if ($next->kind === TokenKind::Symbol && isset(self::COMPARISONS[$next->text])) {
            throw $this->lexer->error('Comparisons don\'t chain: write a < b and b < c.', $next->offset);
        }

        return new Call(self::COMPARISONS[$token->text], [$left, $right]);
    }

    private function concatenation(): Expr {
        $left = $this->additive();
        while ($this->lexer->peek()->isSymbol('~')) {
            $this->lexer->next();
            $left = new Call('concat', [$left, $this->additive()]);
        }

        return $left;
    }

    private function additive(): Expr {
        return $this->binary(self::ADDITIVE, $this->multiplicative(...));
    }

    private function multiplicative(): Expr {
        return $this->binary(self::MULTIPLICATIVE, $this->unary(...));
    }

    /**
     * Left-associative operators of one level.
     * @param array<string, string> $operators
     * @param callable(): Expr $operand
     */
    private function binary(array $operators, callable $operand): Expr {
        $left = $operand();
        while (true) {
            $token = $this->lexer->peek();
            if ($token->kind !== TokenKind::Symbol || !isset($operators[$token->text])) {
                return $left;
            }

            $this->lexer->next();
            $left = new Call($operators[$token->text], [$left, $operand()]);
        }
    }

    private function unary(): Expr {
        if ($this->lexer->peek()->isSymbol('-')) {
            $this->lexer->next();

            return new Call('neg', [$this->unary()]);
        }

        $expr = $this->postfix();
        while ($this->lexer->peek()->isKeyword('as')) {
            $this->lexer->next();
            $expr = new Annotated($expr, $this->typeSyntax());
        }

        return $expr;
    }

    private function postfix(): Expr {
        $expr = $this->primary();
        while (true) {
            $token = $this->lexer->peek();
            if ($token->isSymbol('?.')) {
                $this->lexer->next();
                $expr = new Member($expr, $this->fieldName(), true);
                continue;
            }

            if (!$token->isSymbol('.')) {
                return $expr;
            }

            $this->lexer->next();
            $name = $this->fieldName();
            // items.map(f) is map(items, f).
            $expr = $this->lexer->peek()->isSymbol('(')
                ? new Call($name, [$expr, ...$this->arguments()])
                : new Member($expr, $name);
        }
    }

    private function primary(): Expr {
        $token = $this->lexer->next();

        return match (true) {
            $token->kind === TokenKind::Number => Literal::number($token->text),
            $token->kind === TokenKind::Text => Literal::text($token->text),
            $token->kind === TokenKind::Date => new Literal(LiteralKind::Date, $token->text),
            $token->kind === TokenKind::DateTime => new Literal(LiteralKind::DateTime, $token->text),
            $token->isKeyword('true') => new Literal(LiteralKind::Bool, true),
            $token->isKeyword('false') => new Literal(LiteralKind::Bool, false),
            $token->isKeyword('null') => Literal::null(),
            $token->isKeyword('default') => new DefaultValue(),
            $token->isKeyword('old') => new Old(),
            $token->isKeyword('fail') => new Failure($this->parenthesized()),
            $token->isKeyword('try') => $this->attempt(),
            $token->isKeyword('match') => $this->matchExpression(),
            $token->isKeyword('new') => $this->newObject(),
            $token->isSymbol('(') => $this->closeParenthesis($this->expr()),
            $token->isSymbol('[') => new ListLiteral($this->items(']')),
            $token->isSymbol('{') => new RecordLiteral($this->fields()),
            $token->kind === TokenKind::Word && ($token->quoted || !in_array($token->text, self::KEYWORDS, true)) => $this->lexer->peek()->isSymbol('(')
                ? new Call($token->text, $this->arguments())
                : new Name($token->text),
            default => throw $this->unexpected($token, 'a value'),
        };
    }

    private function attempt(): Attempt {
        $this->expectSymbol('(');
        $body = $this->expr();
        $this->expectSymbol(',');
        $fallback = $this->expr();
        $this->expectSymbol(')');

        return new Attempt($body, $fallback);
    }

    private function matchExpression(): MatchExpression {
        $subject = $this->expr();
        $this->expectSymbol('{');
        $arms = [];
        $else = null;
        while (!$this->lexer->peek()->isSymbol('}')) {
            if ($this->lexer->peek()->isKeyword('else')) {
                $this->lexer->next();
                $this->expectSymbol('=>');
                $else = $this->expr();
                $this->skipComma();
                if (!$this->lexer->peek()->isSymbol('}')) {
                    throw $this->unexpected($this->lexer->peek(), 'the end of the match: else comes last');
                }
                break;
            }

            $values = [$this->matchValue()];
            while ($this->lexer->peek()->isSymbol(',')) {
                $this->lexer->next();
                $values[] = $this->matchValue();
            }
            $this->expectSymbol('=>');
            $arms[] = new MatchArm($values, $this->expr());
            $this->skipComma();
        }
        $this->lexer->next();

        if ($arms === []) {
            throw $this->lexer->error('A match needs at least one arm.', $this->lexer->peek()->offset);
        }

        return new MatchExpression($subject, $arms, $else);
    }

    /** A value a match arm compares with: a literal or a name, a number possibly negative. */
    private function matchValue(): Expr {
        $token = $this->lexer->peek();
        if ($token->isSymbol('-')) {
            $this->lexer->next();
            $number = $this->lexer->next();
            if ($number->kind !== TokenKind::Number) {
                throw $this->unexpected($number, 'a number');
            }

            return new Call('neg', [Literal::number($number->text)]);
        }

        $value = $this->primary();
        if (!$value instanceof Literal && !$value instanceof Name) {
            throw $this->lexer->error('A match arm compares with literals and enum cases only.', $token->offset);
        }

        return $value;
    }

    private function newObject(): NewObject {
        $type = $this->lexer->typeName();
        $this->expectSymbol('{');

        return new NewObject($type, $this->fields());
    }

    /**
     * `name: value, …` up to the closing brace, which is read.
     * @return array<string, Expr>
     */
    private function fields(): array {
        $fields = [];
        while (!$this->lexer->peek()->isSymbol('}')) {
            $token = $this->lexer->peek();
            $name = $this->fieldName();
            if (array_key_exists($name, $fields)) {
                throw $this->lexer->error(sprintf('Field "%s" is given twice.', $name), $token->offset);
            }

            $this->expectSymbol(':');
            $fields[$name] = $this->expr();
            if (!$this->lexer->peek()->isSymbol('}')) {
                $this->expectSymbol(',');
            }
        }
        $this->lexer->next();

        return $fields;
    }

    /** @return list<Expr> */
    private function arguments(): array {
        $this->expectSymbol('(');

        return $this->items(')');
    }

    /**
     * Comma-separated values up to $close, which is read. A word followed
     * by `=>` starts a lambda.
     * @return list<Expr>
     */
    private function items(string $close): array {
        $items = [];
        while (!$this->lexer->peek()->isSymbol($close)) {
            $items[] = $this->item();
            if (!$this->lexer->peek()->isSymbol($close)) {
                $this->expectSymbol(',');
            }
        }
        $this->lexer->next();

        return $items;
    }

    private function item(): Expr {
        $token = $this->lexer->peek();
        if ($token->kind !== TokenKind::Word) {
            return $this->expr();
        }

        $this->lexer->next();
        if (!$this->lexer->peek()->isSymbol('=>')) {
            // Not a lambda: read the word again as the start of a value.
            $this->rewind($token);

            return $this->expr();
        }

        $this->lexer->next();

        return new Lambda($this->checkedVariable($token), $this->expr());
    }

    /** A type in its written form. */
    private function typeSyntax(): Type {
        $token = $this->lexer->next();
        $word = $token->kind === TokenKind::Word && !$token->quoted ? $token->text : '';
        $type = match ($word) {
            'string' => Type::scalar(ScalarType::String),
            'text' => Type::text(),
            'int' => Type::int(),
            'bool' => Type::bool(),
            'float' => Type::float(),
            'decimal' => Type::decimal(),
            'date' => Type::scalar(ScalarType::Date),
            'datetime' => Type::scalar(ScalarType::DateTime),
            'enum' => Type::scalar(ScalarType::Enum, $this->lexer->typeName()),
            'embed' => Type::embed($this->lexer->typeName()),
            'owns' => Type::owned($this->lexer->typeName()),
            'ref' => Type::reference($this->lexer->typeName()),
            'type' => Type::typeReference($this->lexer->typeName()),
            'value' => Type::value($this->lexer->typeName()),
            'list' => Type::list($this->typeSyntax()),
            default => throw $this->unexpected($token, 'a type'),
        };

        if ($word !== 'list' && $this->lexer->peek()->isSymbol('?')) {
            $this->lexer->next();
            $type = $type->orNull();
        }

        return $type;
    }

    private function parenthesized(): Expr {
        $this->expectSymbol('(');

        return $this->closeParenthesis($this->expr());
    }

    private function closeParenthesis(Expr $expr): Expr {
        $this->expectSymbol(')');

        return $expr;
    }

    private function skipComma(): void {
        if ($this->lexer->peek()->isSymbol(',')) {
            $this->lexer->next();
        }
    }

    /** A field's name: any word, quoted or not. */
    private function fieldName(): string {
        $token = $this->lexer->next();
        if ($token->kind !== TokenKind::Word) {
            throw $this->unexpected($token, 'a field name');
        }

        return $token->text;
    }

    /** A variable's name: a word that isn't a keyword, unless quoted. */
    private function variableName(): string {
        return $this->checkedVariable($this->lexer->next());
    }

    private function checkedVariable(Token $token): string {
        if ($token->kind !== TokenKind::Word || (!$token->quoted && in_array($token->text, self::KEYWORDS, true))) {
            throw $this->unexpected($token, 'a name');
        }

        return $token->text;
    }

    /** Reads from $token again: the lexer moves back to it. */
    private function rewind(Token $token): void {
        $this->lexer->rewindTo($token->offset);
    }

    private function expectSymbol(string $symbol): void {
        $token = $this->lexer->next();
        if (!$token->isSymbol($symbol)) {
            throw $this->unexpected($token, '"' . $symbol . '"');
        }
    }

    private function expectKeyword(string $keyword): void {
        $token = $this->lexer->next();
        if (!$token->isKeyword($keyword)) {
            throw $this->unexpected($token, '"' . $keyword . '"');
        }
    }

    private function expectEnd(): void {
        $token = $this->lexer->peek();
        if ($token->kind !== TokenKind::End) {
            throw $this->unexpected($token, 'the end');
        }
    }

    private function unexpected(Token $token, string $expected): SyntaxError {
        $found = match ($token->kind) {
            TokenKind::End => 'the end',
            TokenKind::Text => 'a text',
            default => '"' . $token->text . '"',
        };

        return $this->lexer->error(sprintf('Expected %s, found %s.', $expected, $found), $token->offset);
    }
}
