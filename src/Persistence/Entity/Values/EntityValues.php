<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Values;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * One entity's values, owned objects included under their paths: every value
 * it holds, and the ones it actually set. Anything not touched keeps
 * following its type's default.
 */
final readonly class EntityValues
{
    /**
     * @param string $identifier The entity's own concrete identifier.
     * @param FieldValues $dense Every value it holds, defaulted or not.
     * @param FieldValues $touched Only what differs from what its type would
     *        give: what gets stored and flagged.
     */
    public function __construct(
        public string $identifier,
        public FieldValues $dense,
        public FieldValues $touched,
    ) {
    }
}
