<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Types;

use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;

/** What the caller may do to a type's entities. */
final readonly class TypeAccess
{
    public function __construct(
        public bool $read,
        public bool $create,
        public bool $update,
        public bool $delete,
    ) {
    }

    /** What $permissions allow on $type. */
    public static function of(Permissions $permissions, string $type): self {
        return new self(
            $permissions->may($type, TypeAction::Read),
            $permissions->may($type, TypeAction::Create),
            $permissions->may($type, TypeAction::Update),
            $permissions->may($type, TypeAction::Delete),
        );
    }
}
