<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `fail("…")` as a statement: the aggregate fails, with $message. */
final readonly class FailStatement implements Statement
{
    public function __construct(
        public Expr $message,
    ) {
    }
}
