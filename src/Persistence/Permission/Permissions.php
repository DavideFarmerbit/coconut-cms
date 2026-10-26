<?php

namespace XyloIsCoding\CoconutCms\Persistence\Permission;

use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * What one actor may do: to a type's entities, as each #[TypePermission]
 * from the type up to its root declares it; and to each field, as its
 * #[FieldPermission] declares it on the type declaring the field. No role
 * declared for an action means everyone.
 */
final class Permissions
{
    /** @var array<string, list<string>> unreadableUnder()'s answers, by type. */
    private array $unreadable = [];

    public function __construct(
        private readonly SchemaLookup $schemas,
        private readonly Actor $actor,
    ) {
    }

    /** Whether the actor may do $action to a $type: every declaration from $type up to its root must hold. */
    public function may(string $type, TypeAction $action): bool {
        for ($current = $type; $current !== null; $current = $schema->parent) {
            $schema = $this->schemas->get($current);
            $role = $schema->typePermission?->roleFor($action);
            if ($role !== null && !$this->actor->hasRole($role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * $type and every type extending it that the actor may not read: what a
     * query on $type, or a Reference to it, leaves out.
     * @return list<string>
     */
    public function unreadableUnder(string $type): array {
        if (isset($this->unreadable[$type])) {
            return $this->unreadable[$type];
        }

        $unreadable = [];
        foreach ($this->schemas->identifiers() as $candidate) {
            if ($this->extends($candidate, $type) && !$this->may($candidate, TypeAction::Read)) {
                $unreadable[] = $candidate;
            }
        }

        return $this->unreadable[$type] = $unreadable;
    }

    /** Whether $type is $ancestor or extends it. */
    private function extends(string $type, string $ancestor): bool {
        for ($current = $type; $current !== null; $current = $this->schemas->get($current)->parent) {
            if ($current === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /** Whether the actor may read field $name of type $type, declared on it or an ancestor. */
    public function canRead(string $type, string $name): bool {
        $role = $this->permissionOf($type, $name)?->read;

        return $role === null || $this->actor->hasRole($role);
    }

    /** Whether the actor may write field $name of type $type. */
    public function canWrite(string $type, string $name): bool {
        $role = $this->permissionOf($type, $name)?->write;

        return $role === null || $this->actor->hasRole($role);
    }

    /**
     * Every field of the $identifier aggregate whose value changes from $old
     * to $new without the actor's right to write it, by path. A field the
     * actor may not write is compared whole, with everything under it.
     * @return list<string>
     */
    public function deniedWrites(string $identifier, FieldValues $new, FieldValues $old): array {
        $denied = [];
        $this->compare($identifier, $new, $old, null, FieldPath::TRAVERSE, $denied);

        return $denied;
    }

    /**
     * @param list<string> $denied Filled in.
     */
    private function compare(string $type, FieldValues $new, FieldValues $old, ?FieldPath $root, string $join, array &$denied): void {
        foreach ($this->schemas->hierarchyFieldsOf($type) as $name => $field) {
            $at = $root === null ? FieldPath::of($name) : $root->append($join, FieldPath::of($name));

            if (!$this->canWrite($type, (string) $name)) {
                if (self::sorted($new->under($at)->toArray()) !== self::sorted($old->under($at)->toArray())) {
                    $denied[] = (string) $at;
                }

                continue;
            }

            if ($field->kind() === FieldKind::Embed) {
                $this->compare($field->target(), $new, $old, $at, FieldPath::EMBED, $denied);
            } elseif ($field->kind() === FieldKind::OwningReference && $new->has($at) && $new->get($at) !== null) {
                $this->compare($new->get($at), $new, $old, $at, FieldPath::TRAVERSE, $denied);
            } elseif ($field->kind() === FieldKind::Collection && $new->has($at)) {
                $item = $field->itemDescriptor();
                for ($index = 0; $index < (int) $new->get($at); $index++) {
                    $itemAt = $at->traverse($index);
                    if ($item->kind() === FieldKind::Embed) {
                        $this->compare($item->target(), $new, $old, $itemAt, FieldPath::TRAVERSE, $denied);
                    } elseif ($item->kind() === FieldKind::OwningReference && $new->has($itemAt) && $new->get($itemAt) !== null) {
                        $this->compare($new->get($itemAt), $new, $old, $itemAt, FieldPath::TRAVERSE, $denied);
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed> $values by path: two sets compare the same whatever order they were built in.
     */
    private static function sorted(array $values): array {
        ksort($values, SORT_STRING);

        return $values;
    }

    /** The permission declared on field $name, by $type or the ancestor declaring it. */
    private function permissionOf(string $type, string $name): ?FieldPermission {
        $field = FieldName::of($name);
        for ($current = $type; $current !== null; $current = $this->schemas->get($current)->parent) {
            $schema = $this->schemas->get($current);
            if ($schema->fields->has($field)) {
                return $schema->fieldPermissions->get($field);
            }
        }

        return null;
    }
}
