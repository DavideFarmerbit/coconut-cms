<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/**
 * An Embed's value: `{ amount: 10, currency: EUR }`. Its type comes from
 * where it's used. A field left out follows its default.
 */
final readonly class RecordLiteral implements Expr
{
    /** @param array<string, Expr> $fields */
    public function __construct(
        public array $fields,
    ) {
    }
}
