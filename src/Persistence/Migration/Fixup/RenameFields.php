<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * Several of a type's own fields renamed at once, their values kept: every
 * old name is read as the type has it before any is renamed, so
 * `['a' => 'b', 'b' => 'a']` swaps two fields, and a loop of any length
 * works the same. Only the result is checked: no two fields end up with
 * one name. A unique group over any of them follows.
 *
 *     new RenameFields('shop:boat', ['length' => 'beam', 'beam' => 'length'])
 */
final readonly class RenameFields implements Fixup, MovesValues
{
    /** @var array<string, string> Each old name's new one, each a valid field name. */
    public array $renames;

    /** @param array<string, string> $renames Each old name's new one; one renamed to itself is left out. */
    public function __construct(
        public string $type,
        array $renames,
    ) {
        $kept = [];
        foreach ($renames as $old => $new) {
            if ((string) $old !== $new) {
                $kept[(string) FieldName::of((string) $old)] = (string) FieldName::of($new);
            }
        }

        $this->renames = $kept;
    }

    /** Throws InvalidFixup when an old name is no field of the type's own, or two fields would end with one name. */
    public function applyTo(SchemaState $schemas): SchemaState {
        $schema = $schemas->get($this->type);
        foreach (array_keys($this->renames) as $old) {
            $schemas->field($this->type, FieldName::of($old));
        }

        $names = [];
        foreach ($schema->fields as $name => $field) {
            $final = $this->renames[(string) $name] ?? (string) $name;
            if (isset($names[$final])) {
                throw new InvalidFixup(sprintf('type "%s" would have two fields "%s".', $this->type, $final));
            }
            $names[$final] = true;
        }

        return $schemas->with($schema
            ->withFields($schema->fields->renamedAll($this->renames))
            ->withUniqueGroups(UniqueGroups::withFieldsRenamed($schema->uniqueGroups, $this->renames)));
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach (Occurrences::ofType($this->type, $values, $before) as $occurrence) {
            $moves = [];
            foreach ($this->renames as $old => $new) {
                $moves[] = [$occurrence->fieldPath(FieldName::of($old)), $occurrence->fieldPath(FieldName::of($new))];
            }
            $values->moveAll($moves);
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        $renames = array_map(static fn (string $old, string $new): string => "$old → $new", array_keys($this->renames), $this->renames);

        return sprintf('RenameFields(%s, %s)', $this->type, implode(', ', $renames));
    }
}
