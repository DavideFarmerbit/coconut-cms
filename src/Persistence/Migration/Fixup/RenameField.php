<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A type's own field renamed, its values kept. A unique group over it follows.
 * RenameFields with one rename: to swap two fields' names, or rename in a
 * loop, give RenameFields all of them at once.
 */
final readonly class RenameField implements Fixup, MovesValues
{
    public FieldName $old;
    public FieldName $new;

    /** The same rename, as RenameFields does it. */
    private RenameFields $all;

    public function __construct(
        public string $type,
        string $old,
        string $new,
    ) {
        $this->old = FieldName::of($old);
        $this->new = FieldName::of($new);
        $this->all = new RenameFields($type, [$old => $new]);
    }

    /** Throws InvalidFixup when the type has no such field of its own, or one already has the new name. */
    public function applyTo(SchemaState $schemas): SchemaState {
        return $this->all->applyTo($schemas);
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        $this->all->moveValues($values, $before, $after);
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return sprintf('RenameField(%s, %s → %s)', $this->type, $this->old, $this->new);
    }
}
