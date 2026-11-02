<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * A type renamed, its content kept. Every parent and target naming it follows,
 * in schemas of every source, and so does its table: it takes the one the
 * target gives the renamed type. RenameTypes with one rename: to swap two
 * types' names, or rename in a loop, give RenameTypes all of them at once.
 */
final readonly class RenameType implements Fixup, MovesValues, ChangesTypes
{
    /** The same rename, as RenameTypes does it. */
    private RenameTypes $all;

    public function __construct(
        public string $old,
        public string $new,
    ) {
        $this->all = new RenameTypes([$old => $new]);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        return $this->all->applyTo($schemas);
    }

    /** Every root and owned slot holding the old identifier holds the new one, touched or not as it was. */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        $this->all->moveValues($values, $before, $after);
    }

    public function movedTypes(): array {
        return [$this->old];
    }

    public function describe(): string {
        return sprintf('RenameType(%s → %s)', $this->old, $this->new);
    }
}
