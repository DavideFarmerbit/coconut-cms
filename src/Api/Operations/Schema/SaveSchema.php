<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;

/**
 * Saves an admin's changes to editor-created types, as a migration: their
 * content moves, and the schema revision goes up. Refused before anything
 * changes when the revision is stale, the policy blocks a break, breaks
 * aren't accepted, or entries can't move. Needs the schema-editor role.
 */
#[Operation('coconut-cms:schema.save')]
final readonly class SaveSchema
{
    public function run(SchemaSaveInput $input, OperationContext $context): SchemaReport {
        return (new SchemaSaving($context))->save($input);
    }
}
