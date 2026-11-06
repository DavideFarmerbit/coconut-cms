<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

/**
 * Names every table and column the system adds, and builds the names around
 * a field's stored column. A field's own column comes from its identity,
 * never from its name: an Embed's fields join its column with theirs, with
 * "__".
 *
 * A generated column starts with "f_"; every name the system adds to a table
 * holding fields starts with "_". So the two never collide.
 */
final class ColumnNames
{
    public const EMBED = '__';

    /** What every generated column starts with: "f_a1b2c3". */
    public const FIELD = 'f_';

    /** A level row's, or a side table row's, entity id. */
    public const ID = '_id';

    /** The owner's id, copied onto a level row for unique groups; a Collection item's owner. */
    public const OWNER = '_owner';

    /** The owning field's id, copied onto a level row for unique groups. */
    public const OWNER_FIELD = '_owner_field';

    /** A Collection item's index. */
    public const POSITION = '_position';

    /** A Scalar, ValueObject or Reference Collection item's value. */
    public const VALUE = '_value';

    /** A level row's write token: fresh on every save writing the row. */
    public const WRITTEN = '_written';

    /**
     * What a migration prefixes a column or table with while the old one
     * still holds data the new one needs: "_old__f_a1b2c3".
     */
    public const OLD = '_old__';

    /** The column holding the item count of the Owned Collection stored at $column: "_f_a1b2c3_count". */
    public static function countOf(string $column): string {
        return '_' . $column . '_count';
    }

    /** The table holding a non-owned Collection's items, stored at $column on $table: "car__f_a1b2c3". */
    public static function collectionTable(string $table, string $column): string {
        return $table . self::EMBED . $column;
    }

    /** The side table holding $table's touched flags: "_state__car", "_state__car__f_a1b2c3". */
    public static function stateTable(string $table): string {
        return '_state' . self::EMBED . $table;
    }

    /** $name moved out of the way by a migration: "_old__f_a1b2c3". */
    public static function old(string $name): string {
        return self::OLD . $name;
    }
}
