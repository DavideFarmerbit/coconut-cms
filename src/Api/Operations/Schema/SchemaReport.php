<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSaveReport;

/**
 * What a schema save does, or did: what schema.preview and schema.save give.
 * The editor shows its operations as what the save runs.
 */
final readonly class SchemaReport implements OperationResult
{
    /**
     * @param int $revision The schema revision now: the new one, once saved.
     * @param list<array<string, mixed>> $operations The admin's operations
     *        normalized, as OperationForm writes them: what runs.
     * @param list<string> $fixups Every fixup it runs, described, in order.
     * @param list<SchemaBreak> $breaks Every break it causes on another editor-created type.
     * @param list<string> $blocked Every break the policy blocks, described.
     * @param array<string, string> $failures Why each entry that can't move
     *        fails, by its root's id.
     * @param int $moved How many entries moved: 0 for a preview.
     */
    public function __construct(
        public int $revision,
        public array $operations,
        public array $fixups,
        public array $breaks,
        public array $blocked,
        public array $failures,
        public int $moved,
    ) {
    }

    public static function of(EditorSaveReport $report, OperationForm $form): self {
        return new self(
            $report->revision,
            array_map($form->write(...), $report->operations),
            $report->fixups,
            array_map(SchemaBreak::of(...), $report->breaks),
            array_map(static fn (Difference $blocked): string => $blocked->describe(), $report->blocked),
            $report->failures,
            $report->moved,
        );
    }
}
