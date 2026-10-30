<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use RuntimeException;
use XyloIsCoding\CoconutCms\Persistence\Migration\BreakResolution;

/**
 * An editor save breaking other editor-created types, which the policy would
 * set aside, sent without acceptBreaks: nobody breaks a type without seeing
 * it first. Lists every break.
 */
final class BreaksNotAccepted extends RuntimeException
{
    /** @param list<BreakResolution> $breaks */
    public function __construct(
        public readonly array $breaks,
    ) {
        parent::__construct(sprintf(
            "The save breaks other types; send acceptBreaks to set them aside:\n%s",
            implode("\n", array_map(static fn (BreakResolution $each): string => '- ' . $each->break->describe(), $breaks)),
        ));
    }
}
