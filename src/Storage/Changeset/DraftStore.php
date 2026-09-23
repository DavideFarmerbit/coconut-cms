<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/**
 * Persists an unflushed Changeset, a draft, keyed by a caller-supplied identifier
 * (typically the entity's id, or a TempId's own string for a not-yet-created entity).
 * "Publish" is just handing the saved Changeset straight to a ChangesetFlusher, no
 * separate mechanism.
 */
interface DraftStore
{
    /** Overwrites any existing draft under $key, "a second editor takes over the existing draft" per v1 policy. */
    public function save(string $key, Changeset $changeset): void;

    public function find(string $key): ?Changeset;

    public function discard(string $key): void;
}
