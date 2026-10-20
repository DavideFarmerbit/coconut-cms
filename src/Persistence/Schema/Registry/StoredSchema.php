<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * One persisted schema: the schema itself, its own fingerprint, and the
 * fingerprint every schema it depends on had when it was compiled.
 */
final readonly class StoredSchema
{
    /**
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
        public string $fingerprint,
        public array $dependencies,
    ) {
    }
}
