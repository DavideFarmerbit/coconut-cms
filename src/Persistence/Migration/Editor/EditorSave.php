<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

/**
 * One editor save: the operations an admin made, in order, on the types as
 * they were at $revision. What Migrator::saveEditor() runs and
 * previewEditor() reports on.
 */
final readonly class EditorSave
{
    /**
     * @param int $revision The schema revision the operations were built on.
     * @param list<SchemaOperation> $operations In the order they apply.
     * @param bool $acceptBreaks Whether the admin saw the breaks the save
     *        causes, and accepts the policy setting them aside.
     */
    public function __construct(
        public int $revision,
        public array $operations,
        public bool $acceptBreaks = false,
    ) {
    }
}
