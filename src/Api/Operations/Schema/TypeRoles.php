<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

/** The role a type asks per action on its entities, as setTypePermission takes them; null leaves it to its ancestors. */
final readonly class TypeRoles
{
    public function __construct(
        public ?string $read,
        public ?string $create,
        public ?string $update,
        public ?string $delete,
    ) {
    }
}
