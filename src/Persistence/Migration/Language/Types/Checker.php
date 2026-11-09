<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

use SplObjectStorage;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins\Builtins;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins\Signature;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax\Printer;
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
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\MatchArm;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\MatchExpression;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Member;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Name;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\NewObject;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Old;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\RecordLiteral;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\SetStatement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Statement;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Variable;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * Checks a data step against the shapes before and after its change: every
 * path exists, every value fits where it goes, nothing nullable is used as
 * if it weren't, and every bare name resolves. Gives the step back resolved,
 * with its types, or throws InvalidStep listing every problem.
 */
final class Checker
{
    /**
     * Marks, among the variables, a path or name a condition proved not
     * null. It can't clash with a variable: no name starts with it.
     */
    private const NOT_NULL = "\0not-null:";

    /** @var SplObjectStorage<Expr, Type> */
    private SplObjectStorage $types;

    /** @var SplObjectStorage<Call, Signature> */
    private SplObjectStorage $signatures;

    /** @var SplObjectStorage<Call, string> */
    private SplObjectStorage $defaultPaths;

    /** @var SplObjectStorage<Call, list<string|int>> */
    private SplObjectStorage $enumCases;

    /** @var SplObjectStorage<EnumCase, string|int> */
    private SplObjectStorage $caseValues;

    /** @var SplObjectStorage<Expr, Type> */
    private SplObjectStorage $targets;

    /** @var SplObjectStorage<Expr, true> Expressions that failed to check, already reported. */
    private SplObjectStorage $failed;

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, true> */
    private array $reads = [];

    /** @var array<string, true> */
    private array $writes = [];

    private string $type = '';

    /**
     * @param Shapes $old The shapes before the change: what `old` reads.
     * @param Shapes $new The shapes after it: what `set new` and values written fit.
     */
    public function __construct(
        private readonly Shapes $old,
        private readonly Shapes $new,
        private readonly Builtins $builtins,
    ) {
    }

    public function check(DataStep $step): CheckedStep {
        $this->types = new SplObjectStorage();
        $this->signatures = new SplObjectStorage();
        $this->defaultPaths = new SplObjectStorage();
        $this->enumCases = new SplObjectStorage();
        $this->failed = new SplObjectStorage();
        $this->caseValues = new SplObjectStorage();
        $this->targets = new SplObjectStorage();
        $this->problems = [];
        $this->reads = [];
        $this->writes = [];
        $this->type = $step->type;

        if ($this->old->fieldsOf($step->type) === null || $this->new->fieldsOf($step->type) === null) {
            throw new InvalidStep([sprintf('A step runs in a type that exists both before and after the change; %s doesn\'t.', $step->type)]);
        }

        $sets = [];
        $statements = $this->statements($step->statements, [], $sets);
        if ($this->problems !== []) {
            throw new InvalidStep($this->problems);
        }

        return new CheckedStep(
            new DataStep($step->type, $statements),
            $this->types,
            $this->signatures,
            $this->defaultPaths,
            $this->enumCases,
            $this->caseValues,
            $this->targets,
            array_keys($this->reads),
            array_keys($this->writes),
        );
    }

    /**
     * @param list<Statement> $statements
     * @param array<string, Type> $variables
     * @param list<string> $sets The paths set so far on this run, updated.
     * @return list<Statement>
     */
    private function statements(array $statements, array $variables, array &$sets): array {
        $checked = [];
        foreach ($statements as $statement) {
            if ($statement instanceof LetStatement) {
                if (array_key_exists($statement->name, $variables)) {
                    $this->problem(sprintf('"%s" is already a let here: give the new one another name.', $statement->name));
                }

                $value = $this->expr($statement->value, $variables, null);
                $type = $this->types[$value];
                if ($type->kind === TypeKind::Null || $type->kind === TypeKind::Never) {
                    $this->problem(sprintf('let %s = %s holds no usable value.', $statement->name, Printer::expression($value)));
                }

                $variables[$statement->name] = $type;
                $checked[] = new LetStatement($statement->name, $value);
            } elseif ($statement instanceof SetStatement) {
                $checked[] = $this->set($statement, $variables, $sets);
            } elseif ($statement instanceof IfStatement) {
                $condition = $this->bool($statement->condition, $variables, 'An if\'s condition');
                $thenSets = $sets;
                $elseSets = $sets;
                $then = $this->statements($statement->then, $this->knowing($condition, $variables), $thenSets);
                $else = $this->statements($statement->else, $this->knowing($condition, $variables, true), $elseSets);
                $sets = array_values(array_unique([...$thenSets, ...$elseSets]));
                $checked[] = new IfStatement($condition, $then, $else);
            } elseif ($statement instanceof FailStatement) {
                $checked[] = new FailStatement($this->text($statement->message, $variables, 'fail\'s message'));
            }
        }

        return $checked;
    }

    /**
     * @param array<string, Type> $variables
     * @param list<string> $sets
     */
    private function set(SetStatement $set, array $variables, array &$sets): SetStatement {
        $path = implode('.', $set->path);
        foreach ($sets as $done) {
            if ($done === $path || str_starts_with($done, $path . '.') || str_starts_with($path, $done . '.')) {
                $this->problem(sprintf('new.%s is set twice on one run (with new.%s): set each value once.', $path, $done));
            }
        }
        $sets[] = $path;
        $this->writes[$path] = true;

        $target = $this->newPath($set->path);
        if ($target === null) {
            return $set;
        }

        $value = $this->expr($set->value, $variables, $target, true);
        $this->assign($value, $target, 'new.' . $path);

        return new SetStatement($set->path, $value);
    }

    /**
     * The type at $path of the new shape, from where the step runs; null after reporting a problem.
     * @param list<string> $path
     */
    private function newPath(array $path): ?Type {
        $type = $this->type;
        $at = 'new';
        foreach ($path as $index => $name) {
            $fields = $this->new->fieldsOf($type) ?? [];
            if (!isset($fields[$name])) {
                $this->problem(sprintf('%s has no field "%s" after the change.', $at, $name));

                return null;
            }

            $field = $fields[$name];
            $at .= '.' . $name;
            if ($index === count($path) - 1) {
                return $field;
            }

            if (!$field->hasFields()) {
                $this->problem(sprintf('%s is %s: it has no fields to set.', $at, $field->describe()));

                return null;
            }

            $type = $field->name;
        }

        return null;
    }

    /**
     * $expr checked and resolved, its type recorded.
     * @param array<string, Type> $variables
     * @param Type|null $expected What the place it goes to wants, when known: it types number literals, records and enum cases.
     * @param bool $fillsField Whether it fills a field directly, the one place `default` may be.
     */
    private function expr(Expr $expr, array $variables, ?Type $expected, bool $fillsField = false): Expr {
        try {
            [$resolved, $type] = $this->resolve($expr, $variables, $expected, $fillsField);
        } catch (TypeProblem $problem) {
            // An empty message: a part already reported, nothing more to say.
            if ($problem->getMessage() !== '') {
                $this->problem($problem->getMessage() . ' In: ' . Printer::expression($expr));
            }
            [$resolved, $type] = [$expr, Type::never()];
            $this->failed[$resolved] = true;
        }

        $this->types[$resolved] = $type;

        return $resolved;
    }

    /** Stops checking what holds a part that already failed, so one mistake is reported once. */
    private function unlessFailed(Expr ...$parts): void {
        foreach ($parts as $part) {
            if ($this->failed->contains($part)) {
                throw new TypeProblem('');
            }
        }
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function resolve(Expr $expr, array $variables, ?Type $expected, bool $fillsField): array {
        return match (true) {
            $expr instanceof Literal => [$expr, $this->literal($expr, $expected)],
            $expr instanceof Old => throw new TypeProblem('old alone is no value: read a field of it, old.price.'),
            $expr instanceof Member => $this->member($expr, $variables),
            $expr instanceof Name => $this->name($expr, $variables, $expected),
            $expr instanceof Variable => [$expr, $this->narrowed($expr, $variables[$expr->name] ?? throw new TypeProblem(sprintf('No let or parameter "%s" here.', $expr->name)), $variables)],
            $expr instanceof EnumCase => [$expr = new EnumCase($expr->enum, $expr->case), $this->enumCase($expr)],
            $expr instanceof Call => $this->call($expr, $variables, $expected),
            $expr instanceof Lambda => throw new TypeProblem('A lambda only goes to map, filter or any.'),
            $expr instanceof Conditional => $this->conditional($expr, $variables, $expected, $fillsField),
            $expr instanceof MatchExpression => $this->matchExpression($expr, $variables, $expected, $fillsField),
            $expr instanceof RecordLiteral => $this->record($expr, $variables, $expected),
            $expr instanceof NewObject => $this->newObject($expr, $variables, $expected),
            $expr instanceof ListLiteral => $this->list($expr, $variables, $expected),
            $expr instanceof DefaultValue => $fillsField ? [$expr, Type::default()] : throw new TypeProblem('default only fills a field: set new.x = default, or a record\'s field.'),
            $expr instanceof Failure => [new Failure($this->text($expr->message, $variables, 'fail\'s message')), Type::never()],
            $expr instanceof Attempt => $this->attempt($expr, $variables, $expected, $fillsField),
            $expr instanceof Annotated => $this->annotated($expr, $variables),
            default => throw new TypeProblem(sprintf('Unknown expression %s.', $expr::class)),
        };
    }

    private function literal(Literal $literal, ?Type $expected): Type {
        return match ($literal->kind) {
            LiteralKind::Null => Type::null(),
            LiteralKind::Bool => Type::bool(),
            LiteralKind::Text => Type::text(),
            LiteralKind::Date => Type::scalar(ScalarType::Date),
            LiteralKind::DateTime => Type::scalar(ScalarType::DateTime),
            LiteralKind::Number => match (true) {
                $expected !== null && $expected->is(ScalarType::Decimal, ScalarType::Float) => $expected->nonNull(),
                str_contains($literal->value, '.') => Type::decimal(),
                default => Type::int(),
            },
        };
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function member(Member $member, array $variables): array {
        if ($member->of instanceof Old) {
            $of = $member->of;
            $type = Type::embed($this->type);
            $this->types[$of] = $type;
        } else {
            $of = $this->expr($member->of, $variables, null);
            $type = $this->types[$of];
        }

        $this->unlessFailed($of);
        if ($type->nullable && !$member->safe) {
            throw new TypeProblem(sprintf('%s may be null (%s): read it with ?. instead of .', Printer::expression($of), $type->describe()));
        }

        if (!$type->nullable && $member->safe) {
            throw new TypeProblem(sprintf('%s is never null: read it with . instead of ?.', Printer::expression($of)));
        }

        if (!$type->hasFields()) {
            throw new TypeProblem(sprintf('%s is %s: it has no fields.', Printer::expression($of), $type->describe()));
        }

        $fields = $this->old->fieldsOf($type->name) ?? throw new TypeProblem(sprintf('%s didn\'t exist before the change: old values can\'t be read as one.', $type->name));
        $field = $fields[$member->name] ?? throw new TypeProblem(sprintf('%s had no field "%s" before the change.', $type->name, $member->name));

        $path = $this->oldPath($member);
        if ($path !== null) {
            $this->reads[$path] = true;
        }

        $resolved = new Member($of, $member->name, $member->safe);
        $type = $member->safe ? $field->orNull() : $field;

        return [$resolved, $this->narrowed($resolved, $type, $variables)];
    }

    /**
     * $type without null where a condition already ruled null out for
     * $expr: `if old.email == null then … else lower(old.email)`.
     * @param array<string, Type> $variables
     */
    private function narrowed(Expr $expr, Type $type, array $variables): Type {
        return $type->nullable && isset($variables[self::NOT_NULL . Printer::expression($expr)]) ? $type->nonNull() : $type;
    }

    /**
     * $variables, also knowing that the paths and names $condition proves
     * not null aren't: `x != null`, and each side of an `and`. With
     * $whenFalse, what the condition being false proves: `x == null`.
     * @param array<string, Type> $variables
     * @return array<string, Type>
     */
    private function knowing(Expr $condition, array $variables, bool $whenFalse = false): array {
        if ($condition instanceof Call && count($condition->arguments) === 2) {
            $joins = $whenFalse ? 'or' : 'and';
            if ($condition->function === $joins) {
                return $this->knowing($condition->arguments[1], $this->knowing($condition->arguments[0], $variables, $whenFalse), $whenFalse);
            }

            if ($condition->function === ($whenFalse ? 'eq' : 'ne')) {
                [$a, $b] = $condition->arguments;
                $checked = self::isNull($b) ? $a : (self::isNull($a) ? $b : null);
                if ($checked instanceof Member || $checked instanceof Name || $checked instanceof Variable) {
                    $variables[self::NOT_NULL . Printer::expression($checked)] = Type::never();
                }
            }
        }

        if ($condition instanceof Call && $condition->function === 'not' && count($condition->arguments) === 1) {
            return $this->knowing($condition->arguments[0], $variables, !$whenFalse);
        }

        return $variables;
    }

    private static function isNull(Expr $expr): bool {
        return $expr instanceof Literal && $expr->kind === LiteralKind::Null;
    }

    /** $expr's path from old, "price.amount"; null when it isn't read straight from old. */
    private function oldPath(Expr $expr): ?string {
        $names = [];
        while ($expr instanceof Member) {
            array_unshift($names, $expr->name);
            $expr = $expr->of;
        }

        return $expr instanceof Old ? implode('.', $names) : null;
    }

    /**
     * A bare name: a let or parameter in scope, else a case of the enum expected.
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function name(Name $name, array $variables, ?Type $expected): array {
        $enum = $expected !== null && $expected->is(ScalarType::Enum) ? $expected->name : null;
        $isCase = $enum !== null && array_key_exists($name->name, $this->old->casesOf($enum) ?? $this->new->casesOf($enum) ?? []);

        if (array_key_exists($name->name, $variables)) {
            if ($isCase) {
                throw new TypeProblem(sprintf('"%s" is both a let here and a case of %s: rename the let.', $name->name, $enum));
            }

            $variable = new Variable($name->name);

            return [$variable, $this->narrowed($variable, $variables[$name->name], $variables)];
        }

        if ($enum === null) {
            throw new TypeProblem(sprintf('Unknown name "%s". If it\'s an enum case, nothing here says which enum: write %s as enum <name>.', $name->name, $name->name));
        }

        $case = new EnumCase($enum, $name->name);

        return [$case, $this->enumCase($case)];
    }

    /** $case's type, its backing value recorded. */
    private function enumCase(EnumCase $case): Type {
        $cases = $this->new->casesOf($case->enum) ?? $this->old->casesOf($case->enum) ?? throw new TypeProblem(sprintf('There\'s no enum %s.', $case->enum));
        if (!array_key_exists($case->case, $cases)) {
            throw new TypeProblem(sprintf('%s has no case "%s". It has: %s.', $case->enum, $case->case, implode(', ', array_keys($cases))));
        }

        $this->caseValues[$case] = $cases[$case->case];

        return Type::scalar(ScalarType::Enum, $case->enum);
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function call(Call $call, array $variables, ?Type $expected): array {
        if ($call->function === 'isDefault') {
            return $this->isDefault($call);
        }

        if ($call->function === 'toEnum') {
            return $this->toEnum($call, $variables, $expected);
        }

        $builtin = $this->builtins->get($call->function) ?? throw new TypeProblem(sprintf('There\'s no built-in %s.', $call->function));
        if ($builtin->lambda) {
            return $this->lambdaCall($call, $variables, $expected);
        }

        // A number literal or a bare name takes the type of the other values: price + 1, status == Draft.
        $arguments = [];
        $hint = null;
        if ($builtin->sharesType) {
            foreach ($call->arguments as $at => $argument) {
                if (!self::flexible($argument)) {
                    $arguments[$at] = $this->expr($argument, $variables, null);
                    $hint ??= $this->types[$arguments[$at]];
                }
            }
            $hint = $hint?->nonNull() ?? ($expected?->isNumber() ? $expected->nonNull() : null);
        }

        foreach ($call->arguments as $at => $argument) {
            // The right of `and` runs only when the left is true, the right of `or` only when it's false.
            $known = $at === 1 && in_array($call->function, ['and', 'or'], true)
                ? $this->knowing($arguments[0], $variables, $call->function === 'or')
                : $variables;
            $arguments[$at] ??= $this->expr($argument, $known, $hint);
        }
        ksort($arguments);
        $arguments = array_values($arguments);

        $this->unlessFailed(...$arguments);
        $types = array_map(fn (Expr $argument): Type => $this->types[$argument], $arguments);
        $resolved = new Call($call->function, $arguments);
        $signature = ($builtin->check)($types, $arguments, $expected);
        $this->signatures[$resolved] = $signature;

        return [$resolved, $signature->result];
    }

    /**
     * map, filter, any: the list first, then the lambda with the list's item as its parameter.
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function lambdaCall(Call $call, array $variables, ?Type $expected): array {
        if (count($call->arguments) !== 2 || !$call->arguments[1] instanceof Lambda) {
            throw new TypeProblem(sprintf('%s takes a lambda: list.%s(item => …).', $call->function, $call->function));
        }

        $list = $this->expr($call->arguments[0], $variables, null);
        $this->unlessFailed($list);
        $listType = $this->types[$list];
        if ($listType->kind !== TypeKind::List) {
            throw new TypeProblem(sprintf('%s runs on a list, not %s.', $call->function, $listType->describe()));
        }

        $lambda = $call->arguments[1];
        if (array_key_exists($lambda->parameter, $variables)) {
            throw new TypeProblem(sprintf('"%s" is already a let here: name the parameter otherwise.', $lambda->parameter));
        }

        $bodyExpected = $call->function === 'map' && $expected?->kind === TypeKind::List ? $expected->item : null;
        $body = $this->expr($lambda->body, [...$variables, $lambda->parameter => $listType->item], $bodyExpected);
        $this->unlessFailed($body);
        $resolvedLambda = new Lambda($lambda->parameter, $body);
        $this->types[$resolvedLambda] = $this->types[$body];

        $builtin = $this->builtins->get($call->function);
        $resolved = new Call($call->function, [$list, $resolvedLambda]);
        $signature = ($builtin->check)([$listType, $this->types[$body]], [$list, $resolvedLambda], $expected);
        $this->signatures[$resolved] = $signature;

        return [$resolved, $signature->result];
    }

    /** @return array{Expr, Type} */
    private function isDefault(Call $call): array {
        $path = count($call->arguments) === 1 ? $this->oldPath($call->arguments[0]) : null;
        if ($path === null || $call->arguments[0] instanceof Old) {
            throw new TypeProblem('isDefault takes a field read from old: isDefault(old.price).');
        }

        $argument = $this->expr($call->arguments[0], [], null);
        $resolved = new Call('isDefault', [$argument]);
        $this->defaultPaths[$resolved] = $path;

        return [$resolved, Type::bool()];
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function toEnum(Call $call, array $variables, ?Type $expected): array {
        if ($expected === null || !$expected->is(ScalarType::Enum)) {
            throw new TypeProblem('toEnum needs to know its enum: use it where an enum is expected, or write toEnum(x) as enum <name>.');
        }

        if (count($call->arguments) !== 1) {
            throw new TypeProblem('toEnum takes one value.');
        }

        $argument = $this->expr($call->arguments[0], $variables, null);
        $type = $this->types[$argument];
        if ($type->nullable || (!$type->isText() && !$type->is(ScalarType::Int))) {
            throw new TypeProblem(sprintf('toEnum takes a text or an int, never null, not %s.', $type->describe()));
        }

        $cases = $this->new->casesOf($expected->name) ?? throw new TypeProblem(sprintf('There\'s no enum %s.', $expected->name));
        $resolved = new Call('toEnum', [$argument]);
        $this->enumCases[$resolved] = array_values($cases);
        $result = Type::scalar(ScalarType::Enum, $expected->name);
        $this->signatures[$resolved] = new Signature($result, [$type]);

        return [$resolved, $result];
    }

    /** Whether $expr takes its type from the values around it. */
    private static function flexible(Expr $expr): bool {
        return ($expr instanceof Literal && in_array($expr->kind, [LiteralKind::Number, LiteralKind::Null], true))
            || $expr instanceof Name
            || ($expr instanceof Call && $expr->function === 'neg' && count($expr->arguments) === 1 && self::flexible($expr->arguments[0]));
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function conditional(Conditional $conditional, array $variables, ?Type $expected, bool $fillsField): array {
        $condition = $this->bool($conditional->condition, $variables, 'An if\'s condition');
        $then = $this->expr($conditional->then, $this->knowing($condition, $variables), $expected, $fillsField);
        $else = $this->expr($conditional->else, $this->knowing($condition, $variables, true), $expected ?? $this->types[$then], $fillsField);

        return [new Conditional($condition, $then, $else), $this->unified($this->types[$then], $this->types[$else], 'The branches of if')];
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function matchExpression(MatchExpression $match, array $variables, ?Type $expected, bool $fillsField): array {
        $subject = $this->expr($match->subject, $variables, null);
        $subjectType = $this->types[$subject];
        if ($subjectType->kind !== TypeKind::Scalar || !$subjectType->is(ScalarType::Enum, ScalarType::String, ScalarType::Text, ScalarType::Int, ScalarType::Bool)) {
            throw new TypeProblem(sprintf('match compares an enum, a text, an int or a bool, not %s.', $subjectType->describe()));
        }

        $arms = [];
        $seen = [];
        $type = null;
        foreach ($match->arms as $arm) {
            $values = [];
            foreach ($arm->values as $value) {
                $checked = $this->expr($value, $variables, $subjectType->nonNull());
                $this->assign($checked, $subjectType, 'match ' . Printer::expression($subject));
                $key = Printer::expression($checked);
                if (isset($seen[$key])) {
                    $this->problem(sprintf('match compares with %s twice.', $key));
                }
                $seen[$key] = true;
                $values[] = $checked;
            }

            $result = $this->expr($arm->result, $variables, $expected ?? $type, $fillsField);
            $type = $type === null ? $this->types[$result] : $this->unified($type, $this->types[$result], 'The arms of match');
            $arms[] = new MatchArm($values, $result);
        }

        $else = null;
        if ($match->else !== null) {
            $else = $this->expr($match->else, $variables, $expected ?? $type, $fillsField);
            $type = $this->unified($type, $this->types[$else], 'The arms of match');
        } else {
            $this->exhaustive($subjectType, array_keys($seen));
        }

        return [new MatchExpression($subject, $arms, $else), $type];
    }

    /**
     * A match without an else must cover every case of its enum, and null when it may be null.
     * @param list<string> $covered The values compared with, as written.
     */
    private function exhaustive(Type $subject, array $covered): void {
        if (!$subject->is(ScalarType::Enum)) {
            throw new TypeProblem('A match on anything but an enum needs an else.');
        }

        $cases = $this->new->casesOf($subject->name) ?? $this->old->casesOf($subject->name) ?? [];
        $missing = array_diff(array_keys($cases), $covered);
        if ($subject->nullable && !in_array('null', $covered, true)) {
            $missing[] = 'null';
        }

        if ($missing !== []) {
            throw new TypeProblem(sprintf('The match leaves out %s: cover them, or add an else.', implode(', ', $missing)));
        }
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function record(RecordLiteral $record, array $variables, ?Type $expected): array {
        if ($expected === null || $expected->kind !== TypeKind::Embed) {
            throw new TypeProblem('A record needs a type from where it\'s used: write { … } as embed <name>.');
        }

        return [new RecordLiteral($this->fieldValues($record->fields, $expected->name, $variables)), $expected->nonNull()];
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function newObject(NewObject $object, array $variables, ?Type $expected): array {
        if ($this->new->fieldsOf($object->type) === null) {
            throw new TypeProblem(sprintf('There\'s no type %s after the change.', $object->type));
        }

        if ($expected !== null && $expected->kind === TypeKind::Owned && !$this->new->isA($object->type, $expected->name)) {
            throw new TypeProblem(sprintf('%s is no %s.', $object->type, $expected->name));
        }

        return [new NewObject($object->type, $this->fieldValues($object->fields, $object->type, $variables)), Type::owned($object->type)];
    }

    /**
     * Each given field of $type, checked against its new type. A field left out follows its default.
     * @param array<string, Expr> $fields
     * @param array<string, Type> $variables
     * @return array<string, Expr>
     */
    private function fieldValues(array $fields, string $type, array $variables): array {
        $declared = $this->new->fieldsOf($type) ?? throw new TypeProblem(sprintf('There\'s no type %s after the change.', $type));
        $checked = [];
        foreach ($fields as $name => $value) {
            if (!isset($declared[$name])) {
                $this->problem(sprintf('%s has no field "%s" after the change.', $type, $name));
                continue;
            }

            $checked[$name] = $this->expr($value, $variables, $declared[$name], true);
            $this->assign($checked[$name], $declared[$name], $type . '.' . $name);
        }

        return $checked;
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function list(ListLiteral $list, array $variables, ?Type $expected): array {
        $itemExpected = $expected?->kind === TypeKind::List ? $expected->item : null;
        $items = [];
        $type = $itemExpected;
        foreach ($list->items as $item) {
            $items[] = $checked = $this->expr($item, $variables, $type);
            if ($itemExpected !== null) {
                $this->assign($checked, $itemExpected, 'a list item');
            } else {
                $type = $type === null ? $this->types[$checked] : $this->unified($type, $this->types[$checked], 'The items of a list');
            }
        }

        if ($type === null) {
            throw new TypeProblem('An empty list needs a type from where it\'s used: write [] as list <type>.');
        }

        return [new ListLiteral($items), Type::list($type)];
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function attempt(Attempt $attempt, array $variables, ?Type $expected, bool $fillsField): array {
        $body = $this->expr($attempt->body, $variables, $expected, $fillsField);
        $fallback = $this->expr($attempt->fallback, $variables, $expected ?? $this->types[$body], $fillsField);

        return [new Attempt($body, $fallback), $this->unified($this->types[$body], $this->types[$fallback], 'The two values of try')];
    }

    /**
     * @param array<string, Type> $variables
     * @return array{Expr, Type}
     */
    private function annotated(Annotated $annotated, array $variables): array {
        $inner = $this->expr($annotated->expr, $variables, $annotated->type);
        $this->assign($inner, $annotated->type, Printer::type($annotated->type));

        return [new Annotated($inner, $annotated->type), $annotated->type];
    }

    /** @param array<string, Type> $variables */
    private function bool(Expr $expr, array $variables, string $what): Expr {
        $checked = $this->expr($expr, $variables, Type::bool());
        $type = $this->types[$checked];
        if ($type->kind !== TypeKind::Never && ($type->nullable || !$type->is(ScalarType::Bool))) {
            $this->problem(sprintf('%s must be a bool that\'s never null, not %s. In: %s', $what, $type->describe(), Printer::expression($expr)));
        }

        return $checked;
    }

    /** @param array<string, Type> $variables */
    private function text(Expr $expr, array $variables, string $what): Expr {
        $checked = $this->expr($expr, $variables, Type::text());
        $type = $this->types[$checked];
        if ($type->kind !== TypeKind::Never && ($type->nullable || !$type->isText())) {
            $this->problem(sprintf('%s must be a text that\'s never null, not %s. In: %s', ucfirst($what), $type->describe(), Printer::expression($expr)));
        }

        return $checked;
    }

    private function unified(Type $a, Type $b, string $what): Type {
        return TypeRules::unify($a, $b) ?? throw new TypeProblem(sprintf('%s give %s and %s: they don\'t meet.', $what, $a->describe(), $b->describe()));
    }

    /** Reports a problem unless $value, of its recorded type, fits $target. */
    private function assign(Expr $value, Type $target, string $where): void {
        $this->targets[$value] = $target;
        $problem = $this->misfit($value, $this->types[$value], $target);
        if ($problem !== null) {
            $this->problem(sprintf('%s doesn\'t fit %s (%s): %s', Printer::expression($value), $where, $target->describe(), $problem));
        }
    }

    /** Why a value of type $from doesn't fit $to; null when it does. */
    private function misfit(Expr $value, Type $from, Type $to): ?string {
        if ($from->kind === TypeKind::Never || $from->kind === TypeKind::Default) {
            return null;
        }

        if ($from->kind === TypeKind::Null) {
            return $to->nullable ? null : 'it\'s null, and the field never is.';
        }

        if ($from->nullable && !$to->nullable) {
            return sprintf('it may be null (%s), and the field never is. Handle null first, with ?? or a check.', $from->describe());
        }

        return match (true) {
            $to->kind === TypeKind::Scalar => $this->scalarMisfit($from, $to),
            $to->kind === TypeKind::List => $from->kind !== TypeKind::List ? sprintf('it\'s %s.', $from->describe()) : $this->misfit($value, $from->item, $to->item),
            $from->kind !== $to->kind => sprintf('it\'s %s.', $from->describe()),
            in_array($to->kind, [TypeKind::Embed, TypeKind::Owned], true) => $this->shapeMisfit($value, $from, $to),
            in_array($to->kind, [TypeKind::Reference, TypeKind::TypeReference], true) => $this->new->isA($from->name, $to->name) ? null : sprintf('%s is no %s.', $from->name, $to->name),
            default => $from->name === $to->name ? null : sprintf('it\'s %s.', $from->describe()),
        };
    }

    private function scalarMisfit(Type $from, Type $to): ?string {
        if ($from->kind !== TypeKind::Scalar) {
            return sprintf('it\'s %s.', $from->describe());
        }

        $fits = match (true) {
            $from->scalar === $to->scalar => $from->name === $to->name,
            // Numbers widen; a text going into a string is checked for length when it runs.
            $from->is(ScalarType::Int) && $to->is(ScalarType::Decimal, ScalarType::Float) => true,
            $from->is(ScalarType::Decimal) && $to->is(ScalarType::Float) => true,
            $from->isText() && $to->isText() => true,
            default => false,
        };

        return $fits ? null : sprintf('it\'s %s.%s', $from->describe(), $from->isNumber() && $to->isNumber() ? ' Convert it: toInt, toDecimal, round.' : '');
    }

    /**
     * An Embed or owned object: of the type, or a subtype. Copied from old,
     * its type must have the same fields before and after the change.
     */
    private function shapeMisfit(Expr $value, Type $from, Type $to): ?string {
        if (!$this->new->isA($from->name, $to->name)) {
            return sprintf('%s is no %s.', $from->name, $to->name);
        }

        if (self::builtHere($value) || $this->sameShape($from->name, [])) {
            return null;
        }

        return sprintf('%s changes in this change, so its old values can\'t be copied whole: set its fields one by one.', $from->name);
    }

    /**
     * Whether $value never copies an old value whole: every way it can go
     * gives a record, a new object, a literal, default or a failure.
     */
    private static function builtHere(Expr $value): bool {
        return match (true) {
            $value instanceof RecordLiteral, $value instanceof NewObject, $value instanceof Literal, $value instanceof DefaultValue, $value instanceof Failure => true,
            $value instanceof Annotated => self::builtHere($value->expr),
            $value instanceof Conditional => self::builtHere($value->then) && self::builtHere($value->else),
            $value instanceof Attempt => self::builtHere($value->body) && self::builtHere($value->fallback),
            $value instanceof MatchExpression => ($value->else === null || self::builtHere($value->else))
                && array_filter($value->arms, static fn (MatchArm $arm): bool => !self::builtHere($arm->result)) === [],
            default => false,
        };
    }

    /**
     * Whether $type has the same fields before and after the change, the
     * types its fields hold included.
     * @param array<string, true> $visited
     */
    private function sameShape(string $type, array $visited): bool {
        if (isset($visited[$type])) {
            return true;
        }

        $before = $this->old->fieldsOf($type);
        $after = $this->new->fieldsOf($type);
        if ($before === null || $after === null || array_keys($before) !== array_keys($after)) {
            return false;
        }

        $visited[$type] = true;
        foreach ($before as $name => $field) {
            if (!$field->equals($after[$name])) {
                return false;
            }

            $inner = $field->kind === TypeKind::List ? $field->item : $field;
            if ($inner->hasFields() && !$this->sameShape($inner->name, $visited)) {
                return false;
            }
        }

        return true;
    }

    private function problem(string $problem): void {
        $this->problems[] = $problem;
    }
}
