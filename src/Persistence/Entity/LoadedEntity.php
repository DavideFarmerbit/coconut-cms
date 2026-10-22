<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;

/**
 * One entity as read back, native or editor-created alike: everything it
 * resolves to, as a tree, plus the ids an object built from it needs. What a
 * native loader, or later a dynamic one, builds from.
 */
final readonly class LoadedEntity
{
    /**
     * @param string $identifier Its concrete identifier.
     * @param ObjectNode $tree Every value it resolves to: touched ones as
     *        stored, the rest from its type's defaults as they are now.
     * @param array<string, string> $ownedIds The id of every owned object
     *        that has rows, keyed by its path from this entity.
     * @param list<string> $referencedIds Every id a Reference anywhere in
     *        $tree holds, owned objects and Collections included.
     */
    public function __construct(
        public string $id,
        public string $identifier,
        public ObjectNode $tree,
        public array $ownedIds,
        public array $referencedIds,
    ) {
    }
}
