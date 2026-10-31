<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;

/**
 * A unique group removed from an editor-created type, as DropUniqueGroup
 * removes it. Its fields and their values stay.
 */
final readonly class RemoveUniqueGroup implements SchemaOperation
{
    /** @var list<string> */
    public array $fields;

    public function __construct(
        public string $type,
        string ...$fields,
    ) {
        $this->fields = array_values($fields);
    }

    public function applyTo(SchemaDraft $draft): void {
        $schema = $draft->editorType($this->type);
        $group = UniqueGroup::of(...array_map(FieldName::of(...), $this->fields));
        $groups = UniqueGroups::byKey($schema->uniqueGroups);
        $key = UniqueGroups::keyOf($group);
        if (!isset($groups[$key])) {
            throw new InvalidOperation(sprintf('Type "%s" has no unique group over %s.', $this->type, UniqueGroups::describe($group)));
        }

        unset($groups[$key]);
        $draft->replace($schema->withUniqueGroups(array_values($groups)));
        if (!$draft->isCreated($this->type)) {
            $draft->fixup(new Fixup\DropUniqueGroup($this->type, ...$this->fields));
        }
    }

    public function describe(): string {
        return sprintf('RemoveUniqueGroup(%s, %s)', $this->type, implode(', ', $this->fields));
    }
}
