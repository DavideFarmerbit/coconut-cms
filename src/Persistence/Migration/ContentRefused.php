<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use RuntimeException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Uniqueness\Duplicate;

/**
 * A migration whose content can't take it, found by a dry pass before
 * anything changes, so the site stays as it was: entries whose values can't
 * move (a converter cancelling, a value not fitting its new field), and
 * values a unique index would hold twice. Native or editor alike.
 */
final class ContentRefused extends RuntimeException
{
    /**
     * @param array<string, string> $failures Why each entry can't move, keyed by its root's id.
     * @param list<Duplicate> $duplicates
     */
    public function __construct(
        public readonly array $failures,
        public readonly array $duplicates,
    ) {
        $lines = [];
        foreach ($failures as $id => $reason) {
            $lines[] = sprintf('- %s: %s', $id, $reason);
        }

        foreach ($duplicates as $duplicate) {
            $lines[] = '- duplicate ' . $duplicate->describe();
        }

        parent::__construct(sprintf("The content can't take this migration; nothing changed:\n%s", implode("\n", $lines)));
    }
}
