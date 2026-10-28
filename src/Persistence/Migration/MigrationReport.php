<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;

/**
 * What a migration would do on a database, found without changing anything:
 * what Persistence::check() gives. Run against production before a deploy,
 * it shows what the policy would block, and what it would set aside on
 * editor-created types the developer never saw.
 */
final readonly class MigrationReport
{
    /**
     * @param bool $fresh Whether the database has nothing stored yet: a
     *        migration would create it from the code.
     * @param bool $pending Whether the database differs from the code: a
     *        migration has something to do.
     * @param list<string> $pendingSteps The steps the database hasn't applied, in order.
     * @param list<string> $fixups Every fixup the migration would run, described, in order.
     * @param list<BreakResolution> $breaks Every break it would cause on an
     *        editor-created type, and what the policy does with each.
     * @param list<Difference> $blocked Every difference blocking it.
     */
    public function __construct(
        public bool $fresh,
        public bool $pending,
        public array $pendingSteps,
        public array $fixups,
        public array $breaks,
        public array $blocked,
    ) {
    }

    /** Whether running the migration would throw MigrationBlocked. */
    public function isBlocked(): bool {
        return $this->blocked !== [];
    }

    /** The report as lines a person reads: what a command prints. */
    public function describe(): string {
        if ($this->fresh) {
            return 'The database is empty: a migration creates it from the code.';
        }

        if (!$this->pending) {
            return 'The database matches the code: nothing to migrate.';
        }

        $lines = [$this->isBlocked() ? 'The migration is blocked.' : 'The migration can run.'];
        $lines[] = sprintf('Pending steps: %s.', $this->pendingSteps === [] ? 'none' : implode(', ', $this->pendingSteps));
        foreach ($this->fixups as $fixup) {
            $lines[] = '- ' . $fixup;
        }

        foreach ($this->breaks as $resolution) {
            $lines[] = sprintf('Break: %s %s', $resolution->break->describe(), $resolution->setAside === null ? 'Blocked.' : sprintf('Set aside by %s.', $resolution->setAside->describe()));
        }

        foreach ($this->blocked as $difference) {
            $lines[] = 'Blocking: ' . $difference->describe();
        }

        return implode("\n", $lines);
    }
}
