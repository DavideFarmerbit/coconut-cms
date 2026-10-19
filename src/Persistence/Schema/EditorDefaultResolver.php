<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use InvalidArgumentException;

/**
 * Resolves one field's effective default value for an editor-created scope: its
 * own explicit override if one is stored, otherwise whatever its parent resolves
 * to. A parent can be another editor scope, resolved the same way recursively, or
 * a native class, resolved by reading EntityRegistrar's own always-current
 * reflected default: a native ancestor never needs an override entry of its own,
 * since PHP's own inheritance already gives it a correct, fresh default on every
 * read.
 */
final class EditorDefaultResolver
{
    /**
     * @param array<string, array<string, mixed>> $overrides Keyed by scope, then
     *        field name. A scope absent here, or a field absent within it, has no
     *        override of its own.
     * @param array<string, string> $parents Keyed by scope. The value is either
     *        another scope present in $overrides/$parents, or a native
     *        class-string.
     */
    public function __construct(
        private readonly array $overrides,
        private readonly array $parents,
    ) {
    }

    public function resolve(string $scope, string $field): mixed {
        if (array_key_exists($field, $this->overrides[$scope] ?? [])) {
            return $this->overrides[$scope][$field];
        }

        $parent = $this->parents[$scope] ?? null;
        if ($parent === null) {
            throw new InvalidArgumentException(sprintf(
                '"%s" has no override for "%s" and no parent to fall back to.',
                $scope,
                $field,
            ));
        }

        if (class_exists($parent)) {
            return EntityRegistrar::register($parent)->defaultValues[$field];
        }

        return $this->resolve($parent, $field);
    }
}
