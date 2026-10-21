<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Rows;

/**
 * Every row one entity should have: its entities row, then each level's row
 * and state row, root level first, then each of its non-owned Collections'
 * rows. Never what it owns: each owned entity has rows of its own.
 */
final readonly class EntityRows
{
    /**
     * @param class-string $class
     * @param list<Row> $rows In an order safe to insert: a row always after
     *        the row it points to.
     */
    public function __construct(
        public string $id,
        public string $class,
        public array $rows,
    ) {
    }
}
