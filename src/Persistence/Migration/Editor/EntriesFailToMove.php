<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use RuntimeException;

/**
 * An editor save whose content can't move: a converter cancels on a value,
 * or a value doesn't fit its new field. Found by a dry pass before anything
 * changes, so the site stays as it was. Lists why each entry fails.
 */
final class EntriesFailToMove extends RuntimeException
{
    /** @param array<string, string> $failures Why each entry fails, keyed by its root's id. */
    public function __construct(
        public readonly array $failures,
    ) {
        $lines = [];
        foreach ($failures as $id => $reason) {
            $lines[] = sprintf('- %s: %s', $id, $reason);
        }

        parent::__construct(sprintf("%d entries can't move:\n%s", count($failures), implode("\n", $lines)));
    }
}
