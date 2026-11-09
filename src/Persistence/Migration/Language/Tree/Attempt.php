<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `try(body, fallback)`: $body's value, or $fallback's when $body fails. */
final readonly class Attempt implements Expr
{
    public function __construct(
        public Expr $body,
        public Expr $fallback,
    ) {
    }
}
