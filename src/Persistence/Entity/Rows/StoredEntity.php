<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Rows;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * What the database holds for one entity, owned objects included: its
 * concrete identifier, every value it holds, which of them are touched, and
 * the id of every owned object.
 */
final readonly class StoredEntity
{
    /**
     * @param FieldValues $values Every value, touched or not, in its stored
     *        form, owned objects' values under their paths: content is dense.
     * @param FieldValues $touched Only what's flagged, the same way.
     * @param array<string, string> $ownedIds Keyed by the owned object's path
     *        from this entity.
     */
    public function __construct(
        public string $identifier,
        public FieldValues $values,
        public FieldValues $touched,
        public array $ownedIds,
    ) {
    }
}
