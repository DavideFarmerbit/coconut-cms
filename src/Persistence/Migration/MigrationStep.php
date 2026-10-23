<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;

/**
 * One reviewed set of fixups, under an id the database records once it's
 * applied. Declared through MigrationSteps::add().
 */
final readonly class MigrationStep
{
    /**
     * @param string $id Unique among every step, and never reused.
     * @param list<Fixup> $fixups In the order they run.
     */
    public function __construct(
        public string $id,
        public array $fixups,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A migration step needs an id.');
        }
    }
}
