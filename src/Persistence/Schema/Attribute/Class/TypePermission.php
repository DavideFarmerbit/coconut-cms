<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

use Attribute;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;

/**
 * Declares which role may read, create, update and delete this type's
 * entities. Null means no restriction. A subtype may declare its own: every
 * declaration from the type up to its root must hold, so a subtype only ever
 * adds restrictions. Applies to an entity saved on its own; an owned object
 * follows the root of its aggregate.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TypePermission
{
    /**
     * @param string|null $read Role required to load or query the type.
     * @param string|null $create Role required to save a new entity.
     * @param string|null $update Role required to change a stored entity.
     * @param string|null $delete Role required to delete an entity.
     */
    public function __construct(
        public ?string $read = null,
        public ?string $create = null,
        public ?string $update = null,
        public ?string $delete = null,
    ) {
    }

    /** The role $action requires, null for none. */
    public function roleFor(TypeAction $action): ?string {
        return match ($action) {
            TypeAction::Read => $this->read,
            TypeAction::Create => $this->create,
            TypeAction::Update => $this->update,
            TypeAction::Delete => $this->delete,
        };
    }
}
