<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A field's declaration removed from a type. Its values go wherever their path
 * no longer exists in the new schemas, along with everything an owned field
 * owned. A unique group over it goes with it.
 */
final readonly class DropField implements Fixup
{
    public FieldName $field;

    public function __construct(
        public string $type,
        string $field,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->field($this->type, $this->field);
        $schema = $schemas->get($this->type);

        return $schemas->with($schema
            ->withFields($schema->fields->without($this->field))
            ->withUniqueGroups(UniqueGroups::withoutField($schema->uniqueGroups, $this->field)));
    }

    public function describe(): string {
        return sprintf('DropField(%s, %s)', $this->type, $this->field);
    }
}
