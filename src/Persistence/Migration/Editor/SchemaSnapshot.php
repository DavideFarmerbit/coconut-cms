<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

/**
 * Every type, native and editor-created, as stored now, with the schema
 * revision they're at: what the schema editor loads, and what its save is
 * built on.
 */
final readonly class SchemaSnapshot
{
    /** @param list<SchemaDefinition> $definitions Sorted by identifier. */
    public function __construct(
        public int $revision,
        public array $definitions,
    ) {
    }
}
