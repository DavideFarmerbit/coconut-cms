<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/**
 * One flushed changeset, as logged by an UndoLog: an id other writes can reference as
 * an `expectedOperationId` receipt, and the EntityChangeRecords needed to compute its
 * inverse or check for conflicts.
 */
final readonly class ChangesetOperation
{
    /** @param EntityChangeRecord[] $changes */
    public function __construct(
        public string $id,
        public array $changes,
    ) {
    }

    /**
     * The Changeset that, if flushed, would undo this operation entirely. Reverses
     * record order too, since undoing a "create Category then create Product
     * referencing it" must delete the Product before the Category, the opposite of
     * the order they were created in.
     */
    public function inverse(): Changeset
    {
        return new Changeset(array_map(
            static fn (EntityChangeRecord $record): EntityChange => $record->inverse(),
            array_reverse($this->changes),
        ));
    }
}
