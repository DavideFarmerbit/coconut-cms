<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/** A type's own field renamed, its values kept. A unique group over it follows. */
final readonly class RenameField implements Fixup
{
    public FieldName $old;
    public FieldName $new;

    public function __construct(
        public string $type,
        string $old,
        string $new,
    ) {
        $this->old = FieldName::of($old);
        $this->new = FieldName::of($new);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->field($this->type, $this->old);
        $schemas->assertNoField($this->type, $this->new);
        $schema = $schemas->get($this->type);

        return $schemas->with($schema
            ->withFields($schema->fields->renamed($this->old, $this->new))
            ->withUniqueGroups(UniqueGroups::withFieldRenamed($schema->uniqueGroups, $this->old, $this->new)));
    }

    public function describe(): string {
        return sprintf('RenameField(%s, %s → %s)', $this->type, $this->old, $this->new);
    }
}
