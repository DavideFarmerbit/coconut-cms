<?php

namespace XyloIsCoding\CoconutCms\Storage\Prototype;

use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;

/**
 * Logs schema-mutating operations. Kept separate from the content UndoLog, ARCHITECTURE.md
 * frames both as "one pipeline", but nothing in this phase needs to query across them
 * together (no schema-vs-content conflict detection is in scope yet), so a parallel,
 * simpler store is a deliberate simplification here, not a silent divergence.
 */
interface SchemaUndoLog
{
    /** @param array<string, mixed> $snapshot */
    public function record(SchemaOperationKind $kind, string $prototypeIdentifier, FieldDescriptor $field, array $snapshot = []): SchemaOperation;

    public function get(string $operationId): SchemaOperation;
}
