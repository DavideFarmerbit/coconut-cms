<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Values;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * One entity's values, owned objects included under their paths: every value
 * it holds, and the ones it actually set. Anything not touched keeps
 * following its class's default.
 */
final readonly class EntityValues
{
    /**
     * @param class-string $class The entity's own class.
     * @param FieldValues $dense Every value it holds, defaulted or not.
     * @param FieldValues $touched Only what differs from what its class would
     *        give: what gets stored and flagged.
     */
    public function __construct(
        public string $class,
        public FieldValues $dense,
        public FieldValues $touched,
    ) {
    }
}
