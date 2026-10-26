<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/** One page of a query's results, and where the next one starts. */
final readonly class Page
{
    /**
     * @param list<object> $items The entities, loaded, in order.
     * @param string|null $nextCursor What after() takes for the next page;
     *        null on the last one.
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }

    public function isLast(): bool {
        return $this->nextCursor === null;
    }
}
