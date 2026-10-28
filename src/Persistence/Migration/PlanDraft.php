<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;

/**
 * A migration planned to the end, blocked or not: what MigrationPlanner
 * works out before deciding whether it may run. A dry run reports it whole;
 * a migration runs its plan only when nothing is blocked.
 */
final readonly class PlanDraft
{
    /**
     * @param MigrationPlan $plan Every fixup planned. When anything is
     *        blocked, its schemas still hold what was blocked.
     * @param list<Difference> $blocked Every difference left unexplained, a
     *        break the policy blocks included.
     * @param list<BreakResolution> $breaks Every break the migration causes
     *        on an editor-created type, in the order they were found.
     */
    public function __construct(
        public MigrationPlan $plan,
        public array $blocked,
        public array $breaks,
    ) {
    }

    public function isBlocked(): bool {
        return $this->blocked !== [];
    }
}
