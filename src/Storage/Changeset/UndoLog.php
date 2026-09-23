<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/**
 * Logs every flushed changeset with enough information to compute its inverse, the
 * same primitive both undo and concurrent-write conflict detection are built on top of.
 */
interface UndoLog
{
    /**
     * @param EntityChangeRecord[] $records
     */
    public function record(array $records): ChangesetOperation;

    /** The operation with this id, e.g. to compute its inverse() for undo. */
    public function get(string $operationId): ChangesetOperation;

    /**
     * Every record logged strictly after $operationId, across every operation, oldest
     * first. Every record ever retained if $operationId is null.
     *
     * @return EntityChangeRecord[]
     */
    public function changesSince(?string $operationId): array;
}
