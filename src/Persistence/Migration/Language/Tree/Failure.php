<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `fail("…")` as a value: the aggregate fails, with $message. Fits wherever a value does. */
final readonly class Failure implements Expr
{
    public function __construct(
        public Expr $message,
    ) {
    }
}
