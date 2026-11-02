<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use Countable;
use Iterator;
use IteratorAggregate;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;

/**
 * Every field-level permission override an entity declares, keyed by FieldName.
 * A field absent here carries no restriction, so get() returns null for it
 * rather than throwing: that absence is a meaningful default, not an error.
 * Immutable: with() returns a new collection rather than changing this one.
 * @implements IteratorAggregate<FieldName, FieldPermission>
 */
final readonly class FieldPermissionCollection implements IteratorAggregate, Countable
{
    /** @param array<string, FieldPermission> $permissions */
    private function __construct(
        private array $permissions,
    ) {
    }

    public static function empty(): self {
        return new self([]);
    }

    /** @param array<string, FieldPermission> $permissions */
    public static function fromArray(array $permissions): self {
        return new self($permissions);
    }

    public function with(FieldName $name, FieldPermission $permission): self {
        $permissions = $this->permissions;
        $permissions[(string) $name] = $permission;

        return new self($permissions);
    }

    /** This collection without field $name's permission. */
    public function without(FieldName $name): self {
        $permissions = $this->permissions;
        unset($permissions[(string) $name]);

        return new self($permissions);
    }

    /** This collection with field $old's permission under $new instead. */
    public function renamed(FieldName $old, FieldName $new): self {
        return $this->renamedAll([(string) $old => (string) $new]);
    }

    /**
     * This collection with each field `$renames` names keeping its permission
     * under its new name, all at once: two fields swapping names swap them.
     * @param array<string, string> $renames Each old name's new one.
     */
    public function renamedAll(array $renames): self {
        $permissions = [];
        foreach ($this->permissions as $name => $permission) {
            $permissions[$renames[(string) $name] ?? (string) $name] = $permission;
        }

        return new self($permissions);
    }

    public function get(FieldName $name): ?FieldPermission {
        return $this->permissions[(string) $name] ?? null;
    }

    public function has(FieldName $name): bool {
        return array_key_exists((string) $name, $this->permissions);
    }

    public function getIterator(): Iterator {
        foreach ($this->permissions as $name => $permission) {
            yield FieldName::of($name) => $permission;
        }
    }

    public function count(): int {
        return count($this->permissions);
    }
}
