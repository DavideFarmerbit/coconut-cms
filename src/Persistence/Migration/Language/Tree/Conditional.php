<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `if condition then a else b`, giving a value. */
final readonly class Conditional implements Expr
{
    public function __construct(
        public Expr $condition,
        public Expr $then,
        public Expr $else,
    ) {
    }
}
