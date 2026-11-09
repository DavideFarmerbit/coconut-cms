<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/**
 * A bare name as the parser reads it: a variable, or an enum case the
 * expected type names. The checker resolves it to a Variable or an
 * EnumCase; a stored tree holds neither form unresolved.
 */
final readonly class Name implements Expr
{
    public function __construct(
        public string $name,
    ) {
    }
}
