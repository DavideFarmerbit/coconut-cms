<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/**
 * `match subject { values => result  else => fallback }`. Without an else,
 * the arms must cover every case of an enum subject.
 */
final readonly class MatchExpression implements Expr
{
    /** @param list<MatchArm> $arms */
    public function __construct(
        public Expr $subject,
        public array $arms,
        public ?Expr $else = null,
    ) {
    }
}
