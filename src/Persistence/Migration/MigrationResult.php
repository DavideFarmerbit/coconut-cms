<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

/** What a migration did: for a report, or a test. */
final readonly class MigrationResult
{
    /**
     * @param bool $fresh Whether the database had nothing stored yet.
     * @param MigrationPlan $plan What ran.
     * @param list<string> $recorded The step ids recorded as applied.
     * @param int $moved How many aggregates were moved; deleted ones included.
     * @param string $stateKey The StateKey of the code the database now matches.
     */
    public function __construct(
        public bool $fresh,
        public MigrationPlan $plan,
        public array $recorded,
        public int $moved,
        public string $stateKey,
    ) {
    }
}
