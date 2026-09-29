<?php

namespace XyloIsCoding\CoconutCms\Storage\Schema;

use LogicException;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldDescriptor;

/** The default SchemaUndoLog, scoped to one request/process, same pattern as InMemoryUndoLog. */
final class InMemorySchemaUndoLog implements SchemaUndoLog
{
    /** @var array<string, SchemaOperation> */
    private array $operations = [];

    private int $nextId = 1;

    public function record(SchemaOperationKind $kind, string $prototypeIdentifier, FieldDescriptor $field, array $snapshot = []): SchemaOperation
    {
        $operation = new SchemaOperation((string) $this->nextId++, $kind, $prototypeIdentifier, $field, $snapshot);
        $this->operations[$operation->id] = $operation;

        return $operation;
    }

    public function get(string $operationId): SchemaOperation
    {
        return $this->operations[$operationId] ?? throw new LogicException(sprintf('Unknown schema operation id "%s".', $operationId));
    }
}
