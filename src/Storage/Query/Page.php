<?php

namespace XyloIsCoding\CoconutCms\Storage\Query;

/** One page of Query::get() results: the hydrated items, whether more exist beyond it, and the cursor to fetch them with. */
final readonly class Page
{
    /** @param object[] $items */
    public function __construct(
        public array $items,
        public bool $hasMore,
        public ?Cursor $nextCursor,
    ) {
    }
}
