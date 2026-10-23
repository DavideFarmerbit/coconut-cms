<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

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
 */
final readonly class MoveField implements Fixup
{
    public FieldName $field;

    public function __construct(
        public string $from,
        string $field,
        public string $to,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->field($this->from, $this->field);
        $schemas->assertNoField($this->to, $this->field);
        if (!in_array($this->to, $schemas->codeAncestorsOf($this->from), true) && !in_array($this->from, $schemas->codeAncestorsOf($this->to), true)) {
            throw new InvalidFixup(sprintf('"%s" and "%s" are not in one chain: a field only moves up or down its own chain.', $this->from, $this->to));
        }

        $from = $schemas->get($this->from);
        $schemas = $schemas->with($from
            ->withFields($from->fields->without($this->field))
            ->withUniqueGroups(UniqueGroups::withoutField($from->uniqueGroups, $this->field)));

        $to = $schemas->get($this->to);

        return $schemas->with($to->withFields($to->fields->with($this->field, $schemas->codeField($this->to, $this->field))));
    }

    public function describe(): string {
        return sprintf('MoveField(%s, %s → %s)', $this->field, $this->from, $this->to);
    }
}
