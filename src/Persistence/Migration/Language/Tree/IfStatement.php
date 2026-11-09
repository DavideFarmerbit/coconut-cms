<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `if condition { … } else { … }`: statements run on a condition. */
final readonly class IfStatement implements Statement
{
    /**
     * @param list<Statement> $then
     * @param list<Statement> $else Empty when there's no else.
     */
    public function __construct(
        public Expr $condition,
        public array $then,
        public array $else = [],
    ) {
    }
}
