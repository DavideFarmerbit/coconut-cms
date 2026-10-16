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
     * @param string|null $table Overrides the table name derived from the class's own
     *        short name.
     * @param bool $editorExtensible Whether an editor-created prototype may declare
     *        this class as its parent.
     */
    public function __construct(
        public ?string $table = null,
        public bool $editorExtensible = false,
    ) {
    }
}
