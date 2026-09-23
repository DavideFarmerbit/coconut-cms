<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/**
 * The default DraftStore, scoped to one request/process. A durable, cross-request
 * implementation (a real database table, keyed by session) is a natural later swap,
 * same interface-plus-swappable-default pattern used throughout this codebase.
 */
final class InMemoryDraftStore implements DraftStore
{
    /** @var array<string, Changeset> */
    private array $drafts = [];

    public function save(string $key, Changeset $changeset): void
    {
        $this->drafts[$key] = $changeset;
    }

    public function find(string $key): ?Changeset
    {
        return $this->drafts[$key] ?? null;
    }

    public function discard(string $key): void
    {
        unset($this->drafts[$key]);
    }
}
