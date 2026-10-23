<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;

/**
 * How the diff tells unique groups apart: a group has no name, so it's its set
 * of fields. Listing the same fields in another order is the same group.
 */
final class UniqueGroups
{
    /** $group's fields, sorted and joined: equal for two groups over the same fields. */
    public static function keyOf(UniqueGroup $group): string {
        $names = array_map(static fn (FieldName $name): string => (string) $name, $group->fields());
        sort($names, SORT_STRING);

        return implode(',', $names);
    }

    /** "name" or "name, sku", as a difference names the group. */
    public static function describe(UniqueGroup $group): string {
        return implode(', ', array_map(static fn (FieldName $name): string => sprintf('"%s"', $name), $group->fields()));
    }

    /**
     * $groups without any group over $field: a group can't outlive one of its fields.
     * @param list<UniqueGroup> $groups
     * @return list<UniqueGroup>
     */
    public static function withoutField(array $groups, FieldName $field): array {
        return array_values(array_filter(
            $groups,
            static fn (UniqueGroup $group): bool => !in_array((string) $field, array_map('strval', $group->fields()), true),
        ));
    }

    /**
     * $groups with $old renamed to $new wherever it appears.
     * @param list<UniqueGroup> $groups
     * @return list<UniqueGroup>
     */
    public static function withFieldRenamed(array $groups, FieldName $old, FieldName $new): array {
        return array_map(
            static fn (UniqueGroup $group): UniqueGroup => UniqueGroup::of(...array_map(
                static fn (FieldName $name): FieldName => (string) $name === (string) $old ? $new : $name,
                $group->fields(),
            )),
            $groups,
        );
    }

    /**
     * @param list<UniqueGroup> $groups
     * @return array<string, UniqueGroup> Keyed by keyOf().
     */
    public static function byKey(array $groups): array {
        $byKey = [];
        foreach ($groups as $group) {
            $byKey[self::keyOf($group)] = $group;
        }

        return $byKey;
    }
}
