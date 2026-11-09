<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins\Builtins;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Annotated;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Attempt;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Call;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Conditional;
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
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\NewObject;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Old;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\RecordLiteral;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\SetStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Statement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Variable;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\CheckedStep;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Type;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\TypeKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * Compiles a checked step to PHP source, once, and caches the closure it
 * gives. Nothing from the step reaches the source unescaped: names and
 * texts go through var_export, variables are numbered, and calls come from
 * the built-ins' table. So a stored tree, editor-made included, can't run
 * anything but the language.
 */
final class PhpCompiler
{
    private const R = '\\' . Runtime::class . '::';

    /** @var array<string, CompiledStep> Compiled steps, by their source's hash. */
    private static array $cache = [];

    /** @var array<string, string> The PHP variable of each let and parameter in scope, by name. */
    private array $scope = [];

    private int $variables = 0;

    private function __construct(
        private readonly CheckedStep $checked,
        private readonly Builtins $builtins,
    ) {
    }

    public static function compile(CheckedStep $checked, Builtins $builtins): CompiledStep {
        $source = self::source($checked, $builtins);
        $key = hash('sha256', $source);

        return self::$cache[$key] ??= new CompiledStep($source, eval($source));
    }

    /** The PHP source of $checked: a `return` of a static closure. */
    public static function source(CheckedStep $checked, Builtins $builtins): string {
        $compiler = new self($checked, $builtins);
        $body = $compiler->statements($checked->step->statements, '    ');

        return "return static function (array \$old, array \$defaults): array {\n    \$set = [];\n{$body}    return \$set;\n};\n";
    }

    /** @param list<Statement> $statements */
    private function statements(array $statements, string $indent): string {
        $source = '';
        foreach ($statements as $statement) {
            $source .= match (true) {
                $statement instanceof LetStatement => $indent . $this->let($statement) . "\n",
                $statement instanceof SetStatement => $indent . '$set[' . var_export(implode('.', $statement->path), true) . '] = ' . $this->landing($statement->value) . ";\n",
                $statement instanceof FailStatement => $indent . self::R . 'fail(' . $this->expr($statement->message) . ");\n",
                $statement instanceof IfStatement => $this->ifStatement($statement, $indent),
                default => throw new LogicException(sprintf('No PHP for %s.', $statement::class)),
            };
        }

        return $source;
    }

    private function let(LetStatement $let): string {
        $value = $this->expr($let->value);
        $variable = '$v' . $this->variables++;
        $this->scope[$let->name] = $variable;

        return "$variable = $value;";
    }

    private function ifStatement(IfStatement $statement, string $indent): string {
        $outer = $this->scope;
        $source = $indent . 'if (' . $this->expr($statement->condition) . ") {\n" . $this->statements($statement->then, $indent . '    ');
        $this->scope = $outer;
        if ($statement->else !== []) {
            $source .= $indent . "} else {\n" . $this->statements($statement->else, $indent . '    ');
            $this->scope = $outer;
        }

        return $source . $indent . "}\n";
    }

    /** $value's PHP, converted to what the place it lands in wants. */
    private function landing(Expr $value): string {
        $target = $this->checked->targetOf($value);
        $code = $this->expr($value);

        return $target === null ? $code : $this->coerce($code, $this->checked->typeOf($value), $target);
    }

    private function expr(Expr $expr): string {
        return match (true) {
            $expr instanceof Literal => $this->literal($expr, $this->checked->typeOf($expr)),
            $expr instanceof Old => '$old',
            $expr instanceof Member => self::R . ($expr->safe ? 'safeField(' : 'field(') . $this->expr($expr->of) . ', ' . var_export($expr->name, true) . ')',
            $expr instanceof Variable => $this->scope[$expr->name] ?? throw new LogicException(sprintf('No variable "%s" in scope.', $expr->name)),
            $expr instanceof EnumCase => var_export($this->checked->caseValues[$expr], true),
            $expr instanceof Call => $this->call($expr),
            $expr instanceof Conditional => $this->conditional($expr),
            $expr instanceof MatchExpression => $this->matchExpression($expr),
            $expr instanceof RecordLiteral => $this->fields($expr->fields),
            $expr instanceof NewObject => 'new \\' . Created::class . '(' . var_export($expr->type, true) . ', ' . $this->fields($expr->fields) . ')',
            $expr instanceof ListLiteral => $this->list($expr),
            $expr instanceof DefaultValue => '\\' . FollowsDefault::class . '::Default',
            $expr instanceof Failure => self::R . 'fail(' . $this->expr($expr->message) . ')',
            $expr instanceof Attempt => $this->attempt($expr),
            $expr instanceof Annotated => $this->coerce($this->expr($expr->expr), $this->checked->typeOf($expr->expr), $expr->type),
            default => throw new LogicException(sprintf('No PHP for %s.', $expr::class)),
        };
    }

    /** A literal as PHP, in the stored form of $type. */
    private function literal(Literal $literal, Type $type): string {
        if ($literal->kind !== LiteralKind::Number) {
            return var_export($literal->value, true);
        }

        return match ($type->scalar) {
            ScalarType::Decimal => var_export(Runtime::decimal($literal->value), true),
            ScalarType::Float => var_export((float) $literal->value, true),
            default => (string) (filter_var($literal->value, FILTER_VALIDATE_INT) === false
                ? throw new LogicException(sprintf('%s is too large for an int.', $literal->value))
                : (int) $literal->value),
        };
    }

    private function call(Call $call): string {
        if ($call->function === 'isDefault') {
            return 'isset($defaults[' . var_export($this->checked->defaultPaths[$call], true) . '])';
        }

        if ($call->function === 'toEnum') {
            $enum = $this->checked->typeOf($call)->name;

            return self::R . 'enumOf(' . $this->expr($call->arguments[0]) . ', ' . var_export($this->checked->enumCases[$call], true) . ', ' . var_export($enum, true) . ')';
        }

        $builtin = $this->builtins->get($call->function) ?? throw new LogicException(sprintf('No built-in %s.', $call->function));
        $signature = $this->checked->signatures[$call];
        $code = [];
        $types = [];
        foreach ($call->arguments as $at => $argument) {
            $parameter = $signature->parameters[$at] ?? null;
            $type = $this->checked->typeOf($argument);
            $types[] = $parameter ?? $type;
            $code[] = match (true) {
                $argument instanceof Lambda => $this->lambda($argument),
                // A mode or a number of places: the value itself.
                $parameter === null && $argument instanceof Literal => $argument->kind === LiteralKind::Number ? (string) (int) $argument->value : var_export($argument->value, true),
                $parameter === null => $this->expr($argument),
                default => $this->coerce($this->expr($argument), $type, $parameter),
            };
        }

        return ($builtin->php)($code, $types, $signature->result);
    }

    private function lambda(Lambda $lambda): string {
        $outer = $this->scope;
        $variable = '$p' . $this->variables++;
        $this->scope[$lambda->parameter] = $variable;
        $body = $this->expr($lambda->body);
        $this->scope = $outer;

        return "static fn ($variable) => $body";
    }

    private function conditional(Conditional $conditional): string {
        $type = $this->checked->typeOf($conditional);

        return '(' . $this->expr($conditional->condition) . ' ? ' . $this->branch($conditional->then, $type) . ' : ' . $this->branch($conditional->else, $type) . ')';
    }

    private function matchExpression(MatchExpression $match): string {
        $type = $this->checked->typeOf($match);
        $arms = [];
        foreach ($match->arms as $arm) {
            $values = array_map(fn (Expr $value): string => $this->landing($value), $arm->values);
            $arms[] = implode(', ', $values) . ' => ' . $this->branch($arm->result, $type);
        }
        $arms[] = 'default => ' . ($match->else === null
            ? 'throw new \\' . LogicException::class . "('No arm of the match took the value.')"
            : $this->branch($match->else, $type));

        return 'match (' . $this->expr($match->subject) . ') { ' . implode(', ', $arms) . ' }';
    }

    private function attempt(Attempt $attempt): string {
        $type = $this->checked->typeOf($attempt);

        return self::R . 'attempt(static fn () => ' . $this->branch($attempt->body, $type) . ', static fn () => ' . $this->branch($attempt->fallback, $type) . ')';
    }

    /** One branch of a condition, converted to what the whole gives. */
    private function branch(Expr $branch, Type $type): string {
        return $this->coerce($this->landing($branch), $this->checked->targetOf($branch) ?? $this->checked->typeOf($branch), $type);
    }

    /** @param array<string, Expr> $fields */
    private function fields(array $fields): string {
        $items = [];
        foreach ($fields as $name => $value) {
            $items[] = var_export((string) $name, true) . ' => ' . $this->landing($value);
        }

        return '[' . implode(', ', $items) . ']';
    }

    private function list(ListLiteral $list): string {
        $item = $this->checked->typeOf($list)->item;
        $items = array_map(fn (Expr $value): string => $this->coerce($this->landing($value), $this->checked->targetOf($value) ?? $this->checked->typeOf($value), $item), $list->items);

        return '[' . implode(', ', $items) . ']';
    }

    /** $code, a value of type $from, as a value of type $to: numbers widen, a text checked to fit a string. */
    private function coerce(string $code, Type $from, Type $to): string {
        if (in_array($from->kind, [TypeKind::Never, TypeKind::Null, TypeKind::Default], true)) {
            return $code;
        }

        if ($from->kind === TypeKind::List && $to->kind === TypeKind::List) {
            $variable = '$i' . $this->variables++;
            $item = $this->coerce($variable, $from->item, $to->item);

            return $item === $variable ? $code : "array_map(static fn ($variable) => $item, $code)";
        }

        if ($from->kind !== TypeKind::Scalar || $to->kind !== TypeKind::Scalar || $from->scalar === $to->scalar) {
            return $code;
        }

        return match (true) {
            $from->is(ScalarType::Int) && $to->is(ScalarType::Decimal) => self::R . "decimalOf($code)",
            $to->is(ScalarType::Float) => self::R . "floatOf($code)",
            $to->is(ScalarType::String) => self::R . "fitString($code)",
            default => $code,
        };
    }
}
