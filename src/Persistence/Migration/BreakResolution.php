<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Breaks\EditorBreak;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;

/** One break a migration causes on an editor-created type, and what the policy does with it. */
final readonly class BreakResolution
{
    /** @param Fixup|null $setAside The fixup setting it aside; null when the policy blocks it. */
    public function __construct(
        public EditorBreak $break,
        public ?Fixup $setAside,
    ) {
    }

    public function isBlocked(): bool {
        return $this->setAside === null;
    }
}
