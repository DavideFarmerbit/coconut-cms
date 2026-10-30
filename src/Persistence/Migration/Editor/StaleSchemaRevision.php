<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use RuntimeException;

/**
 * An editor save built on a schema revision that isn't current any more:
 * the types changed since the admin loaded them. Load them again, and
 * replay the operations on them.
 */
final class StaleSchemaRevision extends RuntimeException
{
    public function __construct(
        public readonly int $sent,
        public readonly int $current,
    ) {
        parent::__construct(sprintf('The types changed since revision %d; they are at revision %d now.', $sent, $current));
    }
}
