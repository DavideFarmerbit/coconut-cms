<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Type;

/**
 * `expr as type`: $expr checked against $type, which also tells a number
 * literal, a record or an enum case what it is: `100 as decimal`,
 * `EUR as enum shop:currency`. Converts nothing.
 */
final readonly class Annotated implements Expr
{
    public function __construct(
        public Expr $expr,
        public Type $type,
    ) {
    }
}
