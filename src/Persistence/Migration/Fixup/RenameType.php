<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\OccurrenceKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * A type renamed, its content kept. Every parent and target naming it follows,
 * in schemas of every source, and so does its table: it takes the one the
 * target gives the renamed type.
 */
final readonly class RenameType implements Fixup, MovesValues
{
    public function __construct(
        public string $old,
        public string $new,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $renamed = $schemas->get($this->old)
            ->withIdentifier($this->new)
            ->withTable($schemas->target($this->new)->table);

        return $schemas->renamed($this->old, $renamed)->map(fn (EntitySchema $schema): EntitySchema => $schema
            ->withParent($schema->parent === $this->old ? $this->new : $schema->parent)
            ->withFields($schema->fields->retargeted($this->old, $this->new)));
    }

    /** Every root and owned slot holding the old identifier holds the new one, touched or not as it was. */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach (Occurrences::in($values, $before) as $occurrence) {
            if ($occurrence->kind === OccurrenceKind::Owned && $occurrence->identifier === $this->old) {
                $values->set($occurrence->path, $this->new, $values->markOf($occurrence->path));
            }
        }

        if ($values->identifier === $this->old) {
            $values->identifier = $this->new;
        }
    }

    public function movedTypes(): array {
        return [$this->old];
    }

    public function describe(): string {
        return sprintf('RenameType(%s → %s)', $this->old, $this->new);
    }
}
