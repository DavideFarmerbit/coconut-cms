<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

use InvalidArgumentException;

/** A step that doesn't type-check, with every problem found, not only the first. */
final class InvalidStep extends InvalidArgumentException
{
    /** @param list<string> $problems Each with the text it's about. */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct("The step has problems:\n- " . implode("\n- ", $problems));
    }
}
