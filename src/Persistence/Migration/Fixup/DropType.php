<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\OccurrenceKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * A type removed with its content: its entities and everything they own are
 * deleted, and References to them become null. Where one sits in another
 * entity's owned field, or as an Owned Collection item, that slot becomes
 * null: what it held is gone.
 */
final readonly class DropType implements Fixup, MovesValues
{
    public function __construct(
        public string $type,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        return $schemas->without($this->type);
    }

    /** Empties every owned slot holding the type. An aggregate rooted at it is deleted whole, never moved. */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach (Occurrences::in($values, $before) as $occurrence) {
            if ($occurrence->kind === OccurrenceKind::Owned && $occurrence->identifier === $this->type) {
                $values->removeAt($occurrence->path);
                $values->set($occurrence->path, null, ValueMark::Converted);
            }
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return sprintf('DropType(%s)', $this->type);
    }
}
