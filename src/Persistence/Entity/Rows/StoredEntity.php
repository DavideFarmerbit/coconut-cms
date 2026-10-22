<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Rows;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * What the database holds for one entity, owned objects included: its
 * concrete identifier, its touched values, and the id of every owned object
 * that has rows.
 */
final readonly class StoredEntity
{
    /**
     * @param FieldValues $touched Only what's flagged, in its stored form,
     *        owned objects' values under their paths.
     * @param array<string, string> $ownedIds Keyed by the owned object's path
     *        from this entity.
     */
    public function __construct(
        public string $identifier,
        public FieldValues $touched,
        public array $ownedIds,
    ) {
    }
}
