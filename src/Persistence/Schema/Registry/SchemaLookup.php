<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;

/**
 * Finds a schema by identifier: in a batch being compiled first, then in the
 * registry. The batch always wins, so a schema being recompiled is never read
 * from an older registration. With no batch, it's just the registry.
 */
final readonly class SchemaLookup
{
    /** @param array<string, EntitySchema> $batch Keyed by identifier, not registered yet. */
    public function __construct(
        private SchemaRegistry $registry,
        private array $batch = [],
    ) {
    }

    public function has(string $identifier): bool {
        return isset($this->batch[$identifier]) || $this->registry->has($identifier);
    }

    /** Throws if $identifier is in neither the batch nor the registry. */
    public function get(string $identifier): EntitySchema {
        return $this->batch[$identifier] ?? $this->registry->get($identifier);
    }

    /** $identifier's own fields plus everything it inherits, all the way up. */
    public function hierarchyFieldsOf(string $identifier): FieldDescriptorCollection {
        $schema = $this->get($identifier);
        $inherited = $schema->parent !== null ? $this->hierarchyFieldsOf($schema->parent) : FieldDescriptorCollection::empty();

        return $inherited->merge($schema->fields);
    }
}
