<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/**
 * A new owned object: `new shop:supplier { name: "Acme" }`. The engine gives
 * it its id. A field left out follows its default.
 */
final readonly class NewObject implements Expr
{
    /** @param array<string, Expr> $fields */
    public function __construct(
        public string $type,
        public array $fields,
    ) {
    }
}
