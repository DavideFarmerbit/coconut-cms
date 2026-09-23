<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/**
 * One instruction to create, update, or delete a single entity. Built through the
 * named factories below, never the constructor directly, so a Delete carrying field
 * values, or a Create missing a prototypeClass, can't be constructed at all.
 *
 * `prototypeClass` is required for every kind, not just Create: ids are per-table
 * auto-increment integers, not globally unique, so the flusher needs to be told which
 * entity class (and therefore which table/Repository) a target id belongs to.
 */
final readonly class EntityChange
{
    /**
     * @param string|TempId $target a TempId for a new entity, a real id otherwise
     * @param array<string, mixed> $values field name => new value, empty for delete
     */
    private function __construct(
        public EntityChangeKind $kind,
        public string|TempId $target,
        public string $prototypeClass,
        public array $values,
        public ?string $expectedOperationId = null,
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function create(TempId $tempId, string $prototypeClass, array $values): self
    {
        return new self(EntityChangeKind::Create, $tempId, $prototypeClass, $values);
    }

    /** @param array<string, mixed> $values */
    public static function update(string $entityId, string $prototypeClass, array $values, ?string $expectedOperationId = null): self
    {
        return new self(EntityChangeKind::Update, $entityId, $prototypeClass, $values, $expectedOperationId);
    }

    public static function delete(string $entityId, string $prototypeClass, ?string $expectedOperationId = null): self
    {
        return new self(EntityChangeKind::Delete, $entityId, $prototypeClass, [], $expectedOperationId);
    }

    /**
     * Restoring a deleted entity reuses its original real id rather than getting a
     * fresh one, the one legitimate exception to "creation always gets a new
     * identity." Needed so anything still referencing that id keeps working without
     * repointing.
     *
     * @param array<string, mixed> $values
     */
    public static function restore(string $originalId, string $prototypeClass, array $values): self
    {
        return new self(EntityChangeKind::Create, $originalId, $prototypeClass, $values);
    }
}
