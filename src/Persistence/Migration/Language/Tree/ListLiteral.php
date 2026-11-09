<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `[a, b]`: a list of the values given, in order. */
final readonly class ListLiteral implements Expr
{
    /** @param list<Expr> $items */
    public function __construct(
        public array $items,
    ) {
    }
}
