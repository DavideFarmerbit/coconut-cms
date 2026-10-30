<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use XyloIsCoding\CoconutCms\Persistence\Migration\PlanDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\PlannedFixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;

/**
 * An editor save worked out up to the database: what the Migrator plans
 * before saving it, or reports for a preview. Only the Migrator makes one.
 */
final readonly class PreparedEditorSave
{
    /**
     * @param list<SchemaOperation> $operations The save's operations, normalized: what runs.
     * @param list<StoredSchema> $stored What's stored now.
     * @param string $stateKey The code's StateKey, which the database matches.
     * @param PlanDraft $plan Its fixups and the policy's set-asides, planned to the end.
     * @param CompiledBatch|null $batch The new schemas and defaults; null when the plan is blocked.
     * @param array<string, string> $failures Why each entry that can't move fails, keyed by its root's id.
     */
    public function __construct(
        public array $operations,
        public array $stored,
        public string $stateKey,
        public PlanDraft $plan,
        public ?CompiledBatch $batch,
        public array $failures,
    ) {
    }

    /** What it does, at schema revision $revision, having moved $moved aggregates. */
    public function report(int $revision, int $moved): EditorSaveReport {
        return new EditorSaveReport(
            $revision,
            $this->operations,
            array_map(static fn (PlannedFixup $planned): string => $planned->fixup->describe(), $this->plan->plan->fixups),
            $this->plan->breaks,
            $this->plan->blocked,
            $this->failures,
            $moved,
        );
    }
}
