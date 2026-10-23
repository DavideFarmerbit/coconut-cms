<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\SchemaDiff;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\MigrationPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;

/**
 * Works out what a migration runs, touching nothing: every pending step's
 * fixups run, in order, over the stored schemas; whatever still differs from
 * the code goes to the policy. Either every difference ends up explained, or
 * the migration is blocked, naming each one left.
 */
final readonly class MigrationPlanner
{
    public function __construct(
        private MigrationPolicy $policy = new StrictPolicy(),
    ) {
    }

    /**
     * @param list<StoredSchema> $stored Every stored schema, of every source.
     * @param array<string, EntitySchema> $code The native schemas compiled
     *        from code, keyed by identifier.
     * @param list<MigrationStep> $pending The steps the database hasn't
     *        applied yet, in the order they run.
     */
    public function plan(array $stored, array $code, array $pending): MigrationPlan {
        $schemas = SchemaState::of($stored, $code);
        $fixups = [];

        foreach ($pending as $step) {
            foreach ($step->fixups() as $fixup) {
                try {
                    $after = $fixup->applyTo($schemas);
                } catch (InvalidFixup $problem) {
                    throw InvalidFixup::inStep($step->id(), $fixup, $problem);
                }

                $fixups[] = new PlannedFixup($fixup, $schemas, $after);
                $schemas = $after;
            }
        }

        $blocked = [];
        foreach (SchemaDiff::between($schemas->native(), $code) as $difference) {
            $fixup = $this->policy->fixupFor($difference, $schemas);
            if ($fixup === null) {
                $blocked[] = $difference;
                continue;
            }

            try {
                $after = $fixup->applyTo($schemas);
            } catch (InvalidFixup $problem) {
                throw InvalidFixup::fromPolicy($fixup, $problem);
            }

            $fixups[] = new PlannedFixup($fixup, $schemas, $after);
            $schemas = $after;
        }

        if ($blocked !== []) {
            throw new MigrationBlocked($blocked);
        }

        $left = SchemaDiff::between($schemas->native(), $code);
        if ($left !== []) {
            throw new LogicException(sprintf('The policy resolved a difference without explaining it: %s', $left[0]->describe()));
        }

        return new MigrationPlan(array_map(static fn (MigrationStep $step): string => $step->id(), $pending), $fixups, $schemas);
    }
}
