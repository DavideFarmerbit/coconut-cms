<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `i => i.size`: what a list built-in runs on each item. Only ever a call's argument. */
final readonly class Lambda implements Expr
{
    public function __construct(
        public string $parameter,
        public Expr $body,
    ) {
    }
}
