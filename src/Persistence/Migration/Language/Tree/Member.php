<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** A field read from a record or an owned object: `old.price`, `old.supplier?.email`. */
final readonly class Member implements Expr
{
    /** @param bool $safe `?.`: null when $of is null, instead of an error. */
    public function __construct(
        public Expr $of,
        public string $name,
        public bool $safe = false,
    ) {
    }
}
