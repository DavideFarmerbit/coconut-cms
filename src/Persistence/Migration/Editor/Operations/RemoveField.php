<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * An editor-created type's own field removed, with its values and a unique
 * group over it, as DropField removes them.
 */
final readonly class RemoveField implements SchemaOperation
{
    public FieldName $field;

    public function __construct(
        public string $type,
        string $field,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaDraft $draft): void {
        $draft->ownField($this->type, $this->field);
        $schema = $draft->editorType($this->type);

        $draft->replace($schema
            ->withFields($schema->fields->without($this->field))
            ->withUniqueGroups(UniqueGroups::withoutField($schema->uniqueGroups, $this->field)));
        if (!$draft->isCreated($this->type)) {
            $draft->fixup(new Fixup\DropField($this->type, (string) $this->field));
        }
    }

    public function describe(): string {
        return sprintf('RemoveField(%s, %s)', $this->type, $this->field);
    }
}
