<?php

namespace XyloIsCoding\CoconutCms\Storage;

use SplObjectStorage;

/**
 * Tracks hydrated entities by class and id, so the same row is never hydrated into two
 * different objects. Shared across every Repository, scoped to one request.
 *
 * Also tracks the reverse direction (an instance's own id), needed to write a
 * reference field: given an already-persisted Category instance, what id does it have.
 */
final class IdentityMap
{
    /** @var array<class-string, array<string, object>> */
    private array $instances = [];

    /** @var SplObjectStorage<object, string> */
    private SplObjectStorage $ids;

    public function __construct()
    {
        $this->ids = new SplObjectStorage();
    }

    /** @param class-string $class */
    public function get(string $class, string $id): ?object
    {
        return $this->instances[$class][$id] ?? null;
    }

    /** @param class-string $class */
    public function put(string $class, string $id, object $instance): void
    {
        $this->instances[$class][$id] = $instance;
        $this->ids[$instance] = $id;
    }

    /** @param class-string $class */
    public function forget(string $class, string $id): void
    {
        $instance = $this->instances[$class][$id] ?? null;
        if ($instance !== null) {
            unset($this->ids[$instance]);
        }

        unset($this->instances[$class][$id]);
    }

    /** The id a previously find()/insert()-ed instance was registered under, if any. */
    public function idOf(object $instance): ?string
    {
        return $this->ids[$instance] ?? null;
    }
}
