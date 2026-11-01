<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\TypePermission;

/**
 * An editor-created type given its own role per action, replacing what it
 * had: what #[TypePermission] declares on a native one. Its ancestors' still
 * apply. A null role leaves that action to them; all null clears it.
 */
final readonly class SetTypePermission implements SchemaOperation
{
    public function __construct(
        public string $type,
        public ?string $read = null,
        public ?string $create = null,
        public ?string $update = null,
        public ?string $delete = null,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $schema = $draft->editorType($this->type);
        $none = $this->read === null && $this->create === null && $this->update === null && $this->delete === null;
        $draft->replace($schema->withTypePermission($none ? null : new TypePermission($this->read, $this->create, $this->update, $this->delete)));
    }

    public function describe(): string {
        return sprintf('SetTypePermission(%s)', $this->type);
    }
}
