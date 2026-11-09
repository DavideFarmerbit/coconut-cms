<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/**
 * A built-in called with its arguments. Operators are calls too: `a + b`
 * is `add(a, b)`, `a ?? b` is `coalesce(a, b)`, and `items.map(f)` is
 * `map(items, f)`.
 */
final readonly class Call implements Expr
{
    /** @param list<Expr> $arguments */
    public function __construct(
        public string $function,
        public array $arguments,
    ) {
    }
}
