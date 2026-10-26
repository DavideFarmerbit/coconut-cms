<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/**
 * A condition on the Collection at a path: whereHas(), whereDoesntHave(),
 * whereCount(). Built by Query and Item, never by hand.
 */
final readonly class CollectionCondition
{
    /**
     * @param Item|null $item What an item must meet, for Has and DoesntHave;
     *        null for any item.
     * @param Condition|null $count What the length must meet, for Count.
     */
    public function __construct(
        public CollectionTest $test,
        public string $path,
        public ?Item $item = null,
        public ?Condition $count = null,
    ) {
    }
}
