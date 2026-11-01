<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * An editor-created type's own field given the roles to read and to write it,
 * replacing what it had: what #[FieldPermission] declares on a native one. A
 * null role leaves that action open; both null clears it.
 */
final readonly class SetFieldPermission implements SchemaOperation
{
    public FieldName $field;

    public function __construct(
        public string $type,
        string $field,
        public ?string $read = null,
        public ?string $write = null,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaDraft $draft): void {
        $draft->ownField($this->type, $this->field);
        $schema = $draft->editorType($this->type);
        $draft->replace($schema->withFieldPermissions($this->read === null && $this->write === null
            ? $schema->fieldPermissions->without($this->field)
            : $schema->fieldPermissions->with($this->field, new FieldPermission($this->read, $this->write))));
    }

    public function describe(): string {
        return sprintf('SetFieldPermission(%s.%s, read: %s, write: %s)', $this->type, $this->field, $this->read ?? 'anyone', $this->write ?? 'anyone');
    }
}
