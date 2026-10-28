<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** entities.query's input: a type, conditions, sorts, and which page. */
final readonly class QueryEntitiesInput implements OperationInput
{
    /**
     * @param string $type The type queried; its subtypes' entities are found too.
     * @param list<ConditionInput> $where All must hold.
     * @param list<SortInput> $orderBy First first; ties go by id, oldest first.
     * @param int $limit At most this many a page, from 1 to QueryEntities::MAX_LIMIT.
     * @param string|null $after The nextCursor of the page before; null for the first.
     */
    public function __construct(
        public string $type,
        public array $where = [],
        public array $orderBy = [],
        public int $limit = 20,
        public ?string $after = null,
    ) {
    }
}
