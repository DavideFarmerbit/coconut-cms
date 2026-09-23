<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/**
 * The logged, factual counterpart to an EntityChange: what a flush actually did to one
 * entity, read from its real previous state immediately before applying the change,
 * never from whatever a client had cached. Stored as a diff, not a full snapshot.
 */
final readonly class EntityChangeRecord
{
    /**
     * @param array<string, mixed> $before empty for Create
     * @param array<string, mixed> $after empty for Delete
     */
    public function __construct(
        public EntityChangeKind $kind,
        public string $entityId,
        public string $prototypeClass,
        public array $before,
        public array $after,
    ) {
    }

    /** @return string[] every field name this record touched */
    public function touchedFields(): array
    {
        return array_values(array_unique([...array_keys($this->before), ...array_keys($this->after)]));
    }

    /** The EntityChange that, if flushed, would undo this record. */
    public function inverse(): EntityChange
    {
        return match ($this->kind) {
            EntityChangeKind::Create => EntityChange::delete($this->entityId, $this->prototypeClass),
            EntityChangeKind::Update => EntityChange::update($this->entityId, $this->prototypeClass, $this->before),
            EntityChangeKind::Delete => EntityChange::restore($this->entityId, $this->prototypeClass, $this->before),
        };
    }
}
