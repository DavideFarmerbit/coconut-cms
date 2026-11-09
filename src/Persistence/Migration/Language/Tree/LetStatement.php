<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** `let name = value`: a value read by name in what follows. */
final readonly class LetStatement implements Statement
{
    public function __construct(
        public string $name,
        public Expr $value,
    ) {
    }
}
