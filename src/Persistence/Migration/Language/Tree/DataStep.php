<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/**
 * `data in shop:product { … }`: statements run at every place $type lives,
 * reading `old` and setting `new`.
 */
final readonly class DataStep
{
    /** @param list<Statement> $statements */
    public function __construct(
        public string $type,
        public array $statements,
    ) {
    }
}
