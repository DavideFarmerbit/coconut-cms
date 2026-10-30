<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;

/**
 * An editor-created type renamed, its content kept. Its table follows its new
 * identifier, and every editor-created parent and field naming it follows.
 */
final readonly class RenameType implements SchemaOperation
{
    public function __construct(
        public string $old,
        public string $new,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $schema = $draft->editorType($this->old);
        $created = $draft->isCreated($this->old);
        $draft->assertFreeName($this->new, $created);

        $draft->rename($this->old, $schema->withIdentifier($this->new)->withTable(NativeIdentifiers::tableOf($this->new)));
        if (!$created) {
            $draft->fixup(new Fixup\RenameType($this->old, $this->new));
        }
    }

    public function describe(): string {
        return sprintf('RenameType(%s → %s)', $this->old, $this->new);
    }
}
