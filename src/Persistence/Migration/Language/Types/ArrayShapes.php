<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

/** Shapes given as arrays: for tests, and for checking a step on its own. */
final readonly class ArrayShapes implements Shapes
{
    /**
     * @param array<string, array<string, Type>> $types Each type's own fields, by type.
     * @param array<string, string> $parents Each type's parent, by type.
     * @param array<string, array<string, string|int>> $enums Each enum's cases, by enum.
     */
    public function __construct(
        private array $types = [],
        private array $parents = [],
        private array $enums = [],
    ) {
    }

    public function fieldsOf(string $type): ?array {
        if (!isset($this->types[$type])) {
            return null;
        }

        $parent = $this->parents[$type] ?? null;

        return [...($parent === null ? [] : $this->fieldsOf($parent) ?? []), ...$this->types[$type]];
    }

    public function isA(string $type, string $base): bool {
        for ($at = $type; $at !== null; $at = $this->parents[$at] ?? null) {
            if ($at === $base) {
                return true;
            }
        }

        return false;
    }

    public function casesOf(string $enum): ?array {
        return $this->enums[$enum] ?? null;
    }
}
