<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

/**
 * A new editor-created type, with no fields of its own yet, extending
 * $parent: an editor-created type, or a native one that's editorExtensible.
 * Its table is derived from its identifier, the way a native class's is.
 */
final readonly class CreateType implements SchemaOperation
{
    public function __construct(
        public string $type,
        public ?string $parent = null,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $draft->assertFreeName($this->type, true);
        if ($this->parent !== null && !$draft->schema($this->parent)->editorExtensible) {
            throw new InvalidOperation(sprintf('"%s" doesn\'t allow editor-created subtypes.', $this->parent));
        }

        $draft->create(new EntitySchema(
            identifier: $this->type,
            parent: $this->parent,
            table: NativeIdentifiers::tableOf($this->type),
            editorExtensible: true,
            fields: FieldDescriptorCollection::empty(),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
        ));
    }

    public function describe(): string {
        return $this->parent === null ? sprintf('CreateType(%s)', $this->type) : sprintf('CreateType(%s extends %s)', $this->type, $this->parent);
    }
}
