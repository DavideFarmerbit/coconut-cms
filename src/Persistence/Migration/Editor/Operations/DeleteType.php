<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

/**
 * An editor-created type deleted with its content, as DropType deletes it.
 * What still names it, a subtype or a field, breaks: the policy decides.
 */
final readonly class DeleteType implements SchemaOperation
{
    public function __construct(
        public string $type,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $created = $draft->isCreated($this->type);
        $draft->remove($this->type);
        if (!$created) {
            $draft->fixup(new Fixup\DropType($this->type));
        }
    }

    public function describe(): string {
        return sprintf('DeleteType(%s)', $this->type);
    }
}
