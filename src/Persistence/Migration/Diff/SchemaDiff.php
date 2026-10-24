<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;

/**
 * Every way the schemas in code differ from the ones stored, as data. Compares
 * only what stored content depends on: types, parents, tables, unique groups
 * and each type's own fields. Defaults, validators, permissions,
 * editorExtensible and a field becoming Nullable are never differences.
 */
final class SchemaDiff
{
    /**
     * @param array<string, EntitySchema> $stored Keyed by identifier: the
     *        schemas stored, of the source being migrated.
     * @param array<string, EntitySchema> $code Keyed by identifier: the same
     *        source's schemas, compiled from code.
     * @return list<Difference> Types added first, then each type's own
     *         differences, then types removed.
     */
    public static function between(array $stored, array $code): array {
        $differences = [];

        foreach ($code as $identifier => $schema) {
            $old = $stored[$identifier] ?? null;
            if ($old === null) {
                $differences[] = new TypeAdded($identifier);
                continue;
            }

            array_push($differences, ...self::typeDifferences($old, $schema));
        }

        foreach (array_diff_key($stored, $code) as $identifier => $schema) {
            $differences[] = new TypeRemoved($identifier);
        }

        return $differences;
    }

    /** @return list<Difference> */
    private static function typeDifferences(EntitySchema $old, EntitySchema $new): array {
        $identifier = $new->identifier;
        $differences = [];

        if ($old->parent !== $new->parent) {
            $differences[] = new ParentChanged($identifier, $old->parent, $new->parent);
        }

        if ($old->table !== $new->table) {
            $differences[] = new TableChanged($identifier, $old->table, $new->table);
        }

        foreach ($new->fields as $name => $field) {
            if (!$old->fields->has($name)) {
                $differences[] = new FieldAdded($identifier, $name, $field);
            } elseif ($old->fields->get($name) != $field && !self::onlyWidens($old->fields->get($name), $field)) {
                $differences[] = new FieldChanged($identifier, $name, $old->fields->get($name), $field);
            }
        }

        foreach ($old->fields as $name => $field) {
            if (!$new->fields->has($name)) {
                $differences[] = new FieldRemoved($identifier, $name, $field);
            }
        }

        $oldGroups = UniqueGroups::byKey($old->uniqueGroups);
        $newGroups = UniqueGroups::byKey($new->uniqueGroups);
        foreach (array_diff_key($newGroups, $oldGroups) as $group) {
            $differences[] = new UniqueGroupAdded($identifier, $group);
        }
        foreach (array_diff_key($oldGroups, $newGroups) as $group) {
            $differences[] = new UniqueGroupRemoved($identifier, $group);
        }

        return $differences;
    }

    /**
     * Whether $new only lets $old hold null where it couldn't: every stored
     * value stays valid, so that's no difference.
     */
    private static function onlyWidens(FieldDescriptor $old, FieldDescriptor $new): bool {
        return $old->ownOrItemNullability() === Nullability::NotNullable
            && $new->ownOrItemNullability() === Nullability::Nullable
            && $old->differsOnlyInNullability($new);
    }
}
