<?php

namespace XyloIsCoding\CoconutCms\Storage\Prototype;

use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;

/**
 * A logged DDL action: adding a column inverts to dropping it, cheap and already safe.
 * Dropping a column inverts to re-adding it and restoring the data that was in it,
 * which is why the pre-drop snapshot travels with the operation, not computed lazily.
 */
final readonly class SchemaOperation
{
    /** @param array<string, mixed> $snapshot entity id => the value that column held, only populated for DropColumn */
    public function __construct(
        public string $id,
        public SchemaOperationKind $kind,
        public string $prototypeIdentifier,
        public FieldDescriptor $field,
        public array $snapshot = [],
    ) {
    }
}
