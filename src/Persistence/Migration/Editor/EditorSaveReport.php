<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use XyloIsCoding\CoconutCms\Persistence\Migration\BreakResolution;
use XyloIsCoding\CoconutCms\Persistence\Migration\DefaultBreak;
use XyloIsCoding\CoconutCms\Persistence\Migration\EntrySample;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Uniqueness\Duplicate;

/**
 * What an editor save would do, found without changing anything: what
 * Migrator::previewEditor() gives, and what saveEditor() did.
 */
final readonly class EditorSaveReport
{
    /**
     * @param int $revision The schema revision now: after the save, once it ran.
     * @param list<SchemaOperation> $operations The save's operations, as
     *        given: what it runs, in order.
     * @param list<string> $fixups Every fixup it runs, described, in order.
     * @param list<BreakResolution> $breaks Every break it causes on another
     *        editor-created type, and what the policy does with it.
     * @param list<Difference> $blocked Every break the policy blocks.
     * @param array<string, string> $failures Why each entry that can't move
     *        fails, keyed by its root's id: a converter cancelling, a value
     *        that doesn't fit.
     * @param list<Duplicate> $duplicates Values a unique index would hold twice.
     * @param int $moved How many aggregates moved: 0 for a preview.
     * @param list<DefaultBreak> $defaultBreaks The rules the default of a type
     *        it changes breaks, or of one extending it: warnings, never a
     *        refusal, since a default may be a placeholder on purpose.
     * @param list<EntrySample> $samples The first entries it moves, in id
     *        order, with what changes in each: a preview's, when asked.
     */
    public function __construct(
        public int $revision,
        public array $operations,
        public array $fixups,
        public array $breaks,
        public array $blocked,
        public array $failures = [],
        public array $duplicates = [],
        public int $moved = 0,
        public array $defaultBreaks = [],
        public array $samples = [],
    ) {
    }

    /** Whether the save can't run: the policy blocks it, an entry can't move, or values would be held twice. */
    public function isBlocked(): bool {
        return $this->blocked !== [] || $this->failures !== [] || $this->duplicates !== [];
    }
}
