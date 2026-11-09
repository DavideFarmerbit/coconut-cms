<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** A `let` or a lambda's parameter, read by name. */
final readonly class Variable implements Expr
{
    public function __construct(
        public string $name,
    ) {
    }
}
