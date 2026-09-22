<?php

namespace XyloIsCoding\CoconutCms\Storage;

/**
 * Tracks hydrated entities by class and id, so the same row is never hydrated into two
 * different objects. Shared across every Repository, scoped to one request.
 */
final class IdentityMap
{
    /** @var array<class-string, array<string, object>> */
    private array $instances = [];

    /** @param class-string $class */
    public function get(string $class, string $id): ?object
    {
        return $this->instances[$class][$id] ?? null;
    }

    /** @param class-string $class */
    public function put(string $class, string $id, object $instance): void
    {
        $this->instances[$class][$id] = $instance;
    }

    /** @param class-string $class */
    public function forget(string $class, string $id): void
    {
        unset($this->instances[$class][$id]);
    }
}
