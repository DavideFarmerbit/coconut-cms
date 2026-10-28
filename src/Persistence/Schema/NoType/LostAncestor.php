<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\NoType;

/**
 * A level cut out of an editor-created type's chain. It holds no values:
 * the fields the level gave are broken-field records naming it as their
 * origin. A level with no fields still leaves one, so a chain never changes
 * silently.
 */
final readonly class LostAncestor implements NoTypeRecord
{
    /**
     * @param string $ancestor The identifier of the ancestor lost.
     * @param string|null $newParent The parent the type took instead; null for none.
     */
    public function __construct(
        public string $id,
        public string $ancestor,
        public LostAncestorReason $reason,
        public ?string $newParent,
    ) {
    }

    public function id(): string {
        return $this->id;
    }
}
