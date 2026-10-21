<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Where a Collection of Scalars, ValueObjects, Embeds or References lives:
 * its own table, one row per item, keyed by owner and position. The item kind
 * only decides the value columns.
 */
final readonly class CollectionLayout
{
    /**
     * @param string|null $stateTable The side table holding each Embed item's
     *        touched flags. Null for any other item kind: those items are
     *        always stored whole.
     * @param list<ValueColumn> $itemColumns Paths relative to the item.
     * @param list<list<string>> $uniqueIndexes Column names, owner included.
     */
    public function __construct(
        public FieldPath $path,
        public string $table,
        public ?string $stateTable,
        public array $itemColumns,
        public array $uniqueIndexes,
    ) {
    }
}
