<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

use Attribute;

/**
 * Marks a native class as an Entity, giving it its own entities row and CTI-chain
 * table.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Entity
{
    /**
     * @param string|null $identifier What everything stored names this class by,
     *        as "namespace:key". Defaults to "default:" and the class's short name,
     *        snake-cased. Set it to keep stored content valid across a rename.
     * @param string|null $table Overrides the table name derived from the
     *        identifier.
     * @param bool $editorExtensible Whether an editor-created prototype may declare
     *        this class as its parent.
     */
    public function __construct(
        public ?string $identifier = null,
        public ?string $table = null,
        public bool $editorExtensible = false,
    ) {
    }
}
