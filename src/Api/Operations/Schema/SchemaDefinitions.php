<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;

/** What schema.get gives: every type, native and editor-made, and the schema revision a save built on them sends. */
final readonly class SchemaDefinitions implements OperationResult
{
    /** @param list<TypeDefinition> $types Sorted by identifier. */
    public function __construct(
        public int $revision,
        public array $types,
    ) {
    }
}
