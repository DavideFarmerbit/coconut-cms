<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `set new.price.amount = value`: a value of the shape after the change. */
final readonly class SetStatement implements Statement
{
    /** @param non-empty-list<string> $path Field names from where the step runs. */
    public function __construct(
        public array $path,
        public Expr $value,
    ) {
    }
}
