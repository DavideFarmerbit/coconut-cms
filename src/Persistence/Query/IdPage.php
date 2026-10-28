<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/**
 * One page of a query's results as ids, nothing loaded, and where the next
 * one starts: what Query::idPage() gives, for code reading entities its own
 * way.
 */
final readonly class IdPage
{
    /**
     * @param list<string> $ids The entities' ids, in order.
     * @param string|null $nextCursor What after() takes for the next page;
     *        null on the last one.
     */
    public function __construct(
        public array $ids,
        public ?string $nextCursor,
    ) {
    }

    /** Whether no page follows this one. */
    public function isLast(): bool {
        return $this->nextCursor === null;
    }
}
