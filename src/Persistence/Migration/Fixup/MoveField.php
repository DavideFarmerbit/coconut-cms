<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A field's declaration moved to another type of the same chain: up to an
 * ancestor, or down to a descendant. Its path doesn't change, so its values
 * follow on their own: moved up, every entity keeps its value; moved down,
 * an entity no longer of a type declaring it loses its value. A unique group
 * over it on the type it leaves goes with it.
 *
 * Moved up, entities that never had the field gain it: those of the ancestor
 * and its subtypes that aren't of the type it left. They follow its default,
 * or get what the initializer decides, as with AddField. Moved down, nobody
 * gains it, so an initializer there is an InvalidFixup.
 */
final readonly class MoveField implements Fixup, MovesValues
{
    public FieldName $field;

    public function __construct(
        public string $from,
        string $field,
        public string $to,
        private ?FieldInitializer $initializer = null,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->field($this->from, $this->field);
        $schemas->assertNoField($this->to, $this->field);
        $up = in_array($this->to, $schemas->codeAncestorsOf($this->from), true);
        if (!$up && !in_array($this->from, $schemas->codeAncestorsOf($this->to), true)) {
            throw new InvalidFixup(sprintf('"%s" and "%s" are not in one chain: a field only moves up or down its own chain.', $this->from, $this->to));
        }

        if (!$up && $this->initializer !== null) {
            throw new InvalidFixup(sprintf('field "%s" moves down from "%s" to "%s": nobody gains it, so it has nothing to initialize.', $this->field, $this->from, $this->to));
        }

        $from = $schemas->get($this->from);
        $schemas = $schemas->with($from
            ->withFields($from->fields->without($this->field))
            ->withUniqueGroups(UniqueGroups::withoutField($from->uniqueGroups, $this->field)));

        $to = $schemas->get($this->to);

        return $schemas->with($to->withFields($to->fields->with($this->field, $schemas->codeField($this->to, $this->field))));
    }

    /** Lets the initializer fill the field wherever an entity gains it: of the ancestor, and not of the type it left. */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        if ($this->initializer === null) {
            return;
        }

        $field = $after->field($this->to, $this->field);
        foreach (Occurrences::ofType($this->to, $values, $before) as $occurrence) {
            if (!$before->isA($occurrence->identifier, $this->from)) {
                $values->write($occurrence->fieldPath($this->field), $field, $this->initializer->initialize($values->valuesAt($occurrence->path)));
            }
        }
    }

    public function movedTypes(): array {
        return $this->initializer === null ? [] : [$this->to];
    }

    public function describe(): string {
        return $this->initializer === null
            ? sprintf('MoveField(%s, %s → %s)', $this->field, $this->from, $this->to)
            : sprintf('MoveField(%s, %s → %s, %s)', $this->field, $this->from, $this->to, $this->initializer::class);
    }
}
