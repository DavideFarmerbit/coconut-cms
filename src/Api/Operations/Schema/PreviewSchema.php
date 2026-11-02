<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;

/**
 * What saving an admin's changes to editor-created types would do, found
 * without changing anything: the fixups, the breaks, what blocks it, which
 * entries can't move, and what changes in the first few that do. Needs the
 * schema-editor role.
 */
#[Operation('coconut-cms:schema.preview')]
final readonly class PreviewSchema
{
    public function run(SchemaPreviewInput $input, OperationContext $context): SchemaReport {
        return (new SchemaSaving($context))->preview($input);
    }
}
