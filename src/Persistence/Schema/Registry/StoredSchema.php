<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;

/**
 * One persisted schema: the schema itself, where it came from, its own
 * fingerprint, and the fingerprint every schema it depends on had when it was
 * compiled.
 */
final readonly class StoredSchema
{
    /**
     * @param SchemaSource $source Native or editor-created: what tells a removed
     *        native type from an editor-created one the code never had.
     * @param string $fingerprint A hash of this schema and its own stored values
     *        only, never of its dependencies'.
     * @param array<string, string> $dependencies Every schema this one depends on,
     *        directly or through others, keyed by identifier: each one's own
     *        fingerprint as of this one's compilation. Transitive, so a change
     *        anywhere below is caught without nesting hashes, which a cycle of
     *        OwningReferences would make impossible.
     */
    public function __construct(
        public EntitySchema $schema,
        public SchemaSource $source,
        public string $fingerprint,
        public array $dependencies,
    ) {
    }
}
