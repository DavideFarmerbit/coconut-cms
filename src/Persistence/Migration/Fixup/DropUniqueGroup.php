<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;

/** A unique group removed from a type. Its fields and their values stay. */
final readonly class DropUniqueGroup implements Fixup
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
        $groups = UniqueGroups::byKey($schema->uniqueGroups);
        $key = UniqueGroups::keyOf($this->group);
        if (!isset($groups[$key])) {
            throw new InvalidFixup(sprintf('type "%s" has no unique group over %s at this point.', $this->type, UniqueGroups::describe($this->group)));
        }

        unset($groups[$key]);

        return $schemas->with($schema->withUniqueGroups(array_values($groups)));
    }

    public function describe(): string {
        return sprintf('DropUniqueGroup(%s, %s)', $this->type, UniqueGroups::describe($this->group));
    }
}
