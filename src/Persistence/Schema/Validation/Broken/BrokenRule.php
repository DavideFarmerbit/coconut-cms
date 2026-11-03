<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Broken;

/**
 * A stored rule that can't be built any more: its class is gone, or its
 * constructor no longer takes what was stored. Kept as it was stored, it
 * checks nothing, so nothing is lost: the schema editor shows it, to be
 * fixed or removed. A rule that builds again later is simply a rule again.
 */
interface BrokenRule
{
    /** The rule as it was stored: `{"rule": identifier, "config": {...}}`. */
    public function entry(): array;

    /** Why it can't be built. */
    public function reason(): string;

    /** Whether it was stored broken already: a migration kept it so before, rather than this one finding it. */
    public function wasKept(): bool;

    /** The same rule, kept broken: what a migration that keeps it stores. */
    public function kept(): static;
}
