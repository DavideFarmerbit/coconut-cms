<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultChange;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;

/**
 * An editor-created type no longer pinning its default at a path, or below
 * it: it follows its ancestors there again.
 */
final readonly class InheritDefault implements SchemaOperation
{
    public function __construct(
        public string $type,
        public string $path,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $draft->changeDefault(DefaultChange::inherit($this->type, DefaultPaths::checked($draft, $this->type, $this->path)));
    }

    public function describe(): string {
        return sprintf('InheritDefault(%s, %s)', $this->type, $this->path);
    }
}
