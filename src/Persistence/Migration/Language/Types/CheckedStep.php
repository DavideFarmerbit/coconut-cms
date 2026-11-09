<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

use SplObjectStorage;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins\Signature;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Call;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\DataStep;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\EnumCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Expr;

/**
 * A step the checker passed: its tree with every name resolved, and what a
 * backend needs to compile it. Built only by Checker.
 */
final readonly class CheckedStep
{
    /**
     * @param SplObjectStorage<Expr, Type> $types Every expression's type.
     * @param SplObjectStorage<Call, Signature> $signatures Every built-in call's.
     * @param SplObjectStorage<Call, string> $defaultPaths Each isDefault call's path, "price.amount".
     * @param SplObjectStorage<Call, list<string|int>> $enumCases Each toEnum call's backing values.
     * @param SplObjectStorage<EnumCase, string|int> $caseValues Each enum case's backing value.
     * @param SplObjectStorage<Expr, Type> $targets The type of the place each value lands in: a field, a list item.
     * @param list<string> $reads The old paths the step reads, "price.amount".
     * @param list<string> $writes The new paths it sets.
     */
    public function __construct(
        public DataStep $step,
        public SplObjectStorage $types,
        public SplObjectStorage $signatures,
        public SplObjectStorage $defaultPaths,
        public SplObjectStorage $enumCases,
        public SplObjectStorage $caseValues,
        public SplObjectStorage $targets,
        public array $reads,
        public array $writes,
    ) {
    }

    /** The type the place $value lands in wants; null when it lands nowhere special. */
    public function targetOf(Expr $value): ?Type {
        return $this->targets->contains($value) ? $this->targets[$value] : null;
    }

    public function typeOf(Expr $expr): Type {
        return $this->types[$expr];
    }
}
