<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\MigratingValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A type moved under the parent its target gives it. A level it gains holds
 * fields every existing entity should already have, so it's filled, not added:
 * following its defaults, or by the initializer. A level it loses takes its
 * values with it, unless a fixup before moves them.
 */
final readonly class Reparent implements Fixup, MovesValues
{
    public function __construct(
        public string $type,
        private ?LevelInitializer $initializer = null,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $parent = $schemas->target($this->type)->parent;
        if ($parent !== null) {
            // Moving under a parent that doesn't exist yet: an AddType must come first.
            $schemas->get($parent);
        }

        return $schemas->with($schemas->get($this->type)->withParent($parent));
    }

    /** Lets the initializer fill the gained levels' fields, and only those, wherever an entity of the type or a subclass lives. */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        if ($this->initializer === null) {
            return;
        }

        foreach (Occurrences::ofType($this->type, $values, $before) as $occurrence) {
            $gained = array_diff($after->chainOf($occurrence->identifier), $before->chainOf($occurrence->identifier));
            $writable = [];
            foreach ($gained as $level) {
                array_push($writable, ...array_map(static fn (FieldName $name): string => (string) $name, $after->get($level)->fields->names()));
            }

            $this->initializer->initialize(new MigratingValues($values, $occurrence->path, $occurrence->join, $writable));
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return $this->initializer === null
            ? sprintf('Reparent(%s)', $this->type)
            : sprintf('Reparent(%s, %s)', $this->type, $this->initializer::class);
    }
}
