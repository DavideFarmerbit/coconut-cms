<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

/**
 * One way the schemas in code differ from the ones stored, on one entity type.
 * Compares declarations: a field differs on the type declaring it, never on a
 * subclass or an Embed site using it.
 */
interface Difference
{
    /** The type the difference is on. */
    public function identifier(): string;

    /** One sentence naming the difference: what a blocked migration reports. */
    public function describe(): string;
}
