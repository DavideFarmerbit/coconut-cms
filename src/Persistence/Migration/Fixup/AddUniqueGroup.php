<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;

/** A unique group added over a type's own fields. Fails on content already holding duplicates. */
final readonly class AddUniqueGroup implements Fixup
{
    public UniqueGroup $group;

    public function __construct(
        public string $type,
        string ...$fields,
    ) {
        $this->group = UniqueGroup::of(...array_map(FieldName::of(...), $fields));
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schema = $schemas->get($this->type);
        if (isset(UniqueGroups::byKey($schema->uniqueGroups)[UniqueGroups::keyOf($this->group)])) {
            throw new InvalidFixup(sprintf('type "%s" already has a unique group over %s at this point.', $this->type, UniqueGroups::describe($this->group)));
        }

        foreach ($this->group as $field) {
            $schemas->field($this->type, $field);
        }

        return $schemas->with($schema->withUniqueGroups([...$schema->uniqueGroups, $this->group]));
    }

    public function describe(): string {
        return sprintf('AddUniqueGroup(%s, %s)', $this->type, UniqueGroups::describe($this->group));
    }
}
