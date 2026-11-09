<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** One arm of a `match`: `"EUR", "€" => EUR`. */
final readonly class MatchArm
{
    /** @param list<Expr> $values Literals or enum cases, never anything computed. */
    public function __construct(
        public array $values,
        public Expr $result,
    ) {
    }
}
