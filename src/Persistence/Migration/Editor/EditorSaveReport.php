<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use XyloIsCoding\CoconutCms\Persistence\Migration\BreakResolution;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;

/**
 * What an editor save would do, found without changing anything: what
 * Migrator::previewEditor() gives, and what saveEditor() did.
 */
final readonly class EditorSaveReport
{
    /**
     * @param int $revision The schema revision now: after the save, once it ran.
     * @param list<SchemaOperation> $operations The save's operations,
     *        normalized: what it runs, and what the editor shows.
     * @param list<string> $fixups Every fixup it runs, described, in order.
     * @param list<BreakResolution> $breaks Every break it causes on another
     *        editor-created type, and what the policy does with it.
     * @param list<Difference> $blocked Every break the policy blocks.
     * @param array<string, string> $failures Why each entry that can't move
     *        fails, keyed by its root's id: a converter cancelling, a value
     *        that doesn't fit.
     * @param int $moved How many aggregates moved: 0 for a preview.
     */
    public function __construct(
        public int $revision,
        public array $operations,
        public array $fixups,
        public array $breaks,
        public array $blocked,
        public array $failures = [],
        public int $moved = 0,
    ) {
    }

    /** Whether the save can't run: the policy blocks it, or an entry can't move. */
    public function isBlocked(): bool {
        return $this->blocked !== [] || $this->failures !== [];
    }
}
