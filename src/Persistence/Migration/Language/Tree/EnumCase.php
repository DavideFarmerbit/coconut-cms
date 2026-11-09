<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** One case of an enum, by case name: `EUR` of `shop:currency`. */
final readonly class EnumCase implements Expr
{
    public function __construct(
        public string $enum,
        public string $case,
    ) {
    }
}
