<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MovesValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * An editor save's fixup with the editor-created definitions as they were at
 * its operation: what it takes its new shape from. An editor save runs its
 * operations as given, so the same field can change twice; each fixup heads
 * for its own step, not for the end of the save. Otherwise just the fixup.
 */
final readonly class FixupAtStep implements Fixup, MovesValues
{
    /** @param array<string, EntitySchema> $target The editor-created definitions after its operation, keyed by identifier. */
    public function __construct(
        public Fixup $fixup,
        private array $target,
    ) {
    }

    /** Applies the fixup heading for its step, and gives the state back heading for the end of the save. */
    public function applyTo(SchemaState $schemas): SchemaState {
        return $this->fixup->applyTo($schemas->withEditorTarget($this->target))->withEditorTarget($schemas->editorTarget());
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        if ($this->fixup instanceof MovesValues) {
            $this->fixup->moveValues($values, $before, $after);
        }
    }

    public function movedTypes(): array {
        return $this->fixup instanceof MovesValues ? $this->fixup->movedTypes() : [];
    }

    public function describe(): string {
        return $this->fixup->describe();
    }
}
