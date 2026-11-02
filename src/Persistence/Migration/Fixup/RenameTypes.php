<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\OccurrenceKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * Several types renamed at once, their content kept: every old identifier is
 * read as it is before any is renamed, so two types swap names, and a loop
 * of any length works the same. Only the result is checked: no two types
 * end up with one identifier. Every parent and target naming one follows,
 * in schemas of every source, and so does its table: it takes the one the
 * target gives the renamed type.
 *
 *     new RenameTypes(['shop:boat' => 'shop:ship', 'shop:ship' => 'shop:boat'])
 */
final readonly class RenameTypes implements Fixup, MovesValues
{
    /** @var array<string, string> Each old identifier's new one. */
    public array $renames;

    /** @param array<string, string> $renames Each old identifier's new one; one renamed to itself is left out. */
    public function __construct(array $renames) {
        $this->renames = array_filter($renames, static fn (string $new, string $old): bool => $old !== $new, ARRAY_FILTER_USE_BOTH);
    }

    /** Throws InvalidFixup when an old identifier names no type, or two would end with one. */
    public function applyTo(SchemaState $schemas): SchemaState {
        $renamed = [];
        foreach ($this->renames as $old => $new) {
            $renamed[$old] = $schemas->get($old)->withIdentifier($new)->withTable($schemas->target($new)->table);
        }

        return $schemas->renamedAll($renamed)->map(fn (EntitySchema $schema): EntitySchema => $schema
            ->withParent($schema->parent === null ? null : $this->renames[$schema->parent] ?? $schema->parent)
            ->withFields($schema->fields->retargetedAll($this->renames)));
    }

    /** Every root and owned slot holding an old identifier holds its new one, touched or not as it was. */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach (Occurrences::in($values, $before) as $occurrence) {
            if ($occurrence->kind === OccurrenceKind::Owned && isset($this->renames[$occurrence->identifier])) {
                $values->set($occurrence->path, $this->renames[$occurrence->identifier], $values->markOf($occurrence->path));
            }
        }

        if (isset($this->renames[$values->identifier])) {
            $values->identifier = $this->renames[$values->identifier];
        }
    }

    public function movedTypes(): array {
        return array_keys($this->renames);
    }

    public function describe(): string {
        return sprintf('RenameTypes(%s)', implode(', ', array_map(static fn (string $old, string $new): string => "$old → $new", array_keys($this->renames), $this->renames)));
    }
}
