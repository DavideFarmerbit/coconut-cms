<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Breaks\EditorBreak;
use XyloIsCoding\CoconutCms\Persistence\Migration\Breaks\EditorBreaks;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\SchemaDiff;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\MigrationPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;

/**
 * Works out what a migration runs, touching nothing: every pending step's
 * fixups run, in order, over the stored schemas; whatever still differs from
 * the code goes to the policy. Either every difference ends up explained, or
 * the migration is blocked, naming each one left.
 *
 * What the schemas break on an editor-created type is a difference too, found
 * after each fixup by EditorBreaks, and decided by the policy: a set-aside
 * goes just before the fixup that caused the break.
 */
final readonly class MigrationPlanner
{
    /** How many times one fixup may cause breaks again after its set-asides: past it, something loops. */
    private const MAX_ROUNDS = 100;

    public function __construct(
        private MigrationPolicy $policy = new StrictPolicy(),
    ) {
    }

    /**
     * @param list<StoredSchema> $stored Every stored schema, of every source.
     * @param array<string, EntitySchema> $code The native schemas compiled
     *        from code, keyed by identifier.
     * @param MigrationSteps $pending The steps the database hasn't applied
     *        yet, in the order they run.
     */
    public function plan(array $stored, array $code, MigrationSteps $pending): MigrationPlan {
        $schemas = SchemaState::of($stored, $code);
        $fixups = [];
        $blocked = [];

        foreach ($pending->all() as $step) {
            foreach ($step->fixups as $fixup) {
                try {
                    $schemas = $this->planFixup($fixup, $schemas, $fixups, $blocked);
                } catch (InvalidFixup $problem) {
                    throw InvalidFixup::inStep($step->id, $fixup, $problem);
                }
            }
        }

        foreach (SchemaDiff::between($schemas->native(), $code) as $difference) {
            $fixup = $this->policy->fixupFor($difference, $schemas);
            if ($fixup === null) {
                $blocked[] = $difference;
                continue;
            }

            try {
                $schemas = $this->planFixup($fixup, $schemas, $fixups, $blocked);
            } catch (InvalidFixup $problem) {
                throw InvalidFixup::fromPolicy($fixup, $problem);
            }
        }

        // What no fixup caused, a native type no longer allowing
        // editor-created subtypes, is set aside once every fixup ran.
        // Setting one aside may show another: a field that now clashes with
        // the new parent's.
        for ($left = $this->unresolvedBreaks($schemas, $blocked); $left !== []; $left = $this->unresolvedBreaks($schemas, $blocked)) {
            foreach ($left as $key => $break) {
                $setAside = $this->policy->fixupFor($break, $schemas);
                if ($setAside === null) {
                    $blocked[$key] = $break;
                    continue;
                }

                $schemas = $this->planSetAside($setAside, $schemas, $fixups);
            }
        }

        if ($blocked !== []) {
            throw new MigrationBlocked(array_values($blocked));
        }

        $left = SchemaDiff::between($schemas->native(), $code);
        if ($left !== []) {
            throw new LogicException(sprintf('The policy resolved a difference without explaining it: %s', $left[0]->describe()));
        }

        return new MigrationPlan($pending->ids(), $fixups, $schemas);
    }

    /**
     * Plans $fixup on $schemas. When applying it breaks an editor-created
     * type in a new way, the policy decides each break; a set-aside goes just
     * before $fixup, on the schemas as they are there, and $fixup is applied
     * again after it. So a capture holds what every earlier fixup made of
     * the field, and nothing of $fixup's.
     * @param list<PlannedFixup> $fixups Filled in.
     * @param array<string|int, Difference> $blocked Filled in: a break the
     *        policy blocks, by its key.
     * @return SchemaState The schemas after $fixup.
     */
    private function planFixup(Fixup $fixup, SchemaState $schemas, array &$fixups, array &$blocked): SchemaState {
        for ($round = 0; ; $round++) {
            $after = $fixup->applyTo($schemas);
            $caused = array_diff_key(EditorBreaks::in($after, $schemas), EditorBreaks::in($schemas), $blocked);
            if ($caused === []) {
                break;
            }

            if ($round >= self::MAX_ROUNDS) {
                throw new LogicException(sprintf('Setting aside what %s breaks keeps breaking more.', $fixup->describe()));
            }

            foreach ($caused as $key => $break) {
                $setAside = $this->policy->fixupFor($break, $schemas);
                if ($setAside === null) {
                    $blocked[$key] = $break;
                    continue;
                }

                $schemas = $this->planSetAside($setAside, $schemas, $fixups);
            }
        }

        $fixups[] = new PlannedFixup($fixup, $schemas, $after);

        return $after;
    }

    /**
     * Plans the policy's $setAside on $schemas.
     * @param list<PlannedFixup> $fixups Filled in.
     */
    private function planSetAside(Fixup $setAside, SchemaState $schemas, array &$fixups): SchemaState {
        try {
            $after = $setAside->applyTo($schemas);
        } catch (InvalidFixup $problem) {
            throw InvalidFixup::fromPolicy($setAside, $problem);
        }

        $fixups[] = new PlannedFixup($setAside, $schemas, $after);

        return $after;
    }

    /**
     * Every break $schemas has that isn't blocked yet.
     * @param array<string|int, Difference> $blocked
     * @return array<string, EditorBreak> Keyed by key().
     */
    private function unresolvedBreaks(SchemaState $schemas, array $blocked): array {
        return array_diff_key(EditorBreaks::in($schemas), $blocked);
    }
}
