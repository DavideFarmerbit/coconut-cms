<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

/**
 * Values a converter, initializer or transformation reads, in stored form,
 * keyed by path relative to where it looks from. Each is either touched or
 * following its old default.
 */
interface Values
{
    public function has(string $path): bool;

    /** The value at $path. Throws when there's none: a typo never reads as null. */
    public function get(string $path): mixed;

    /** Whether the value at $path follows its old default rather than being touched. */
    public function isDefault(string $path): bool;

    /** @return list<string> Every path holding a value. */
    public function paths(): array;
}
