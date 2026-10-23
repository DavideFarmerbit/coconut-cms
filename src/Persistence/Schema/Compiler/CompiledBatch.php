<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * What compiling a batch of blueprints produced, before anything is written or
 * registered: what SchemaCompiler::compute() returns and store() writes. A
 * migration compares it with what's stored before storing it.
 */
final readonly class CompiledBatch
{
    /**
     * @param array<string, EntitySchema> $schemas Keyed by identifier, in
     *        dependency order: a parent or Embed target before what uses it.
     * @param array<string, FieldValues> $values Each schema's stored default
     *        values, keyed by identifier: only what differs from what it would
     *        otherwise resolve to.
     * @param list<StoredSchema> $stored Each schema with its fingerprints, as
     *        the schema store keeps it.
     */
    public function __construct(
        public array $schemas,
        public array $values,
        public array $stored,
    ) {
    }
}
