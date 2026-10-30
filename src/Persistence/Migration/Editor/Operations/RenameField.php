<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/** An editor-created type's own field renamed, its values, defaults and unique groups kept. */
final readonly class RenameField implements SchemaOperation
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

    public function applyTo(SchemaDraft $draft): void {
        $draft->ownField($this->type, $this->old);
        $draft->assertNoField($this->type, $this->new);
        $schema = $draft->editorType($this->type);

        $draft->replace($schema
            ->withFields($schema->fields->renamed($this->old, $this->new))
            ->withUniqueGroups(UniqueGroups::withFieldRenamed($schema->uniqueGroups, $this->old, $this->new)));
        if (!$draft->isCreated($this->type)) {
            $draft->fixup(new Fixup\RenameField($this->type, (string) $this->old, (string) $this->new));
        }
    }

    public function describe(): string {
        return sprintf('RenameField(%s, %s → %s)', $this->type, $this->old, $this->new);
    }
}
