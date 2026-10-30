<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultChange;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;

/**
 * An editor-created type pinning its default at a path, in stored form: its
 * own field's, or one it inherits. Pinned, it stays whatever its ancestors
 * give later. Applied once the content moved, so the path names the final
 * fields; the compile checks the value against its field.
 */
final readonly class SetDefault implements SchemaOperation
{
    public function __construct(
        public string $type,
        public string $path,
        public DefaultValue $value,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $draft->changeDefault(DefaultChange::pin($this->type, DefaultPaths::checked($draft, $this->type, $this->path), $this->value));
    }

    public function describe(): string {
        return sprintf('SetDefault(%s, %s)', $this->type, $this->path);
    }
}
