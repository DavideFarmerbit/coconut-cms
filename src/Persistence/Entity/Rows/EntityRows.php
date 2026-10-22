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
     * @param string $identifier The entity's concrete identifier.
     * @param list<Row> $rows Its entities row first, then the rest in an
     *        order safe to insert: a row always after the row it points to.
     */
    public function __construct(
        public string $id,
        public string $identifier,
        public array $rows,
    ) {
    }

    /** Its row in the entities table: what other entities' rows point to. */
    public function entitiesRow(): Row {
        return $this->rows[0];
    }

    /**
     * Every row but its entities row: its levels' and Collections' rows.
     * @return list<Row>
     */
    public function contentRows(): array {
        return array_slice($this->rows, 1);
    }
}
