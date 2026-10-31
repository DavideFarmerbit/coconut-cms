<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Uniqueness\Duplicate;

/** What a migration's dry pass found in the content: what would make it fail once started. */
final readonly class ContentCheck
{
    /**
     * @param array<string, string> $failures Why each entry can't move, keyed by its root's id.
     * @param list<Duplicate> $duplicates Values a unique index would hold twice.
     */
    public function __construct(
        public array $failures,
        public array $duplicates,
    ) {
    }

    /** Whether the content takes the migration. */
    public function passes(): bool {
        return $this->failures === [] && $this->duplicates === [];
    }

    /** Throws ContentRefused unless the content takes the migration. */
    public function assertPasses(): void {
        if (!$this->passes()) {
            throw new ContentRefused($this->failures, $this->duplicates);
        }
    }
}
