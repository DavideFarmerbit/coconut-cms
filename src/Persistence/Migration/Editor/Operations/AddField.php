<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultChange;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * A field added to an editor-created type, with the default it pins, if any.
 * Existing entries follow the default, or get what $initializer decides. A
 * type this save creates has no entries, so it takes no initializer.
 */
final readonly class AddField implements SchemaOperation
{
    public function __construct(
        public string $type,
        public FieldDescriptor $field,
        public ?FieldInitializer $initializer = null,
        public ?DefaultValue $default = null,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $schema = $draft->editorType($this->type);
        $name = FieldName::of($this->field->name());
        $draft->assertNoField($this->type, $name);
        $created = $draft->isCreated($this->type);
        if ($created && $this->initializer !== null) {
            throw new InvalidOperation(sprintf('Type "%s" is new in this save: it has no entries to initialize.', $this->type));
        }

        $draft->replace($schema->withFields($schema->fields->with($name, $this->field)));
        if (!$created) {
            $draft->fixup(new Fixup\AddField($this->type, (string) $name, $this->initializer));
        }

        if ($this->default !== null) {
            $draft->changeDefault(DefaultChange::pin($this->type, FieldPath::of($name), $this->default));
        }
    }

    public function describe(): string {
        return sprintf('AddField(%s, %s)', $this->type, $this->field->name());
    }
}
