<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Rows;

use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;

/**
 * Where an entity sits: who owns it, through which field, at which position.
 * What its entities row holds besides its id and class.
 */
final readonly class Placement
{
    private function __construct(
        public string $owner,
        public string $ownerField,
        public int $position,
    ) {
    }

    /** No real owner: it belongs to the seeded Cms row. */
    public static function standalone(): self {
        return new self(EntityIds::NIL, '', -1);
    }

    /**
     * Owned by $owner through the field with the id $ownerField. $position is
     * the item's index in an Owned Collection, or -1 for a singular
     * OwningReference.
     */
    public static function owned(string $owner, string $ownerField, int $position = -1): self {
        return new self($owner, $ownerField, $position);
    }
}
