<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Derives the real table and column names a FieldPath lands on, and names
 * every one the system adds. Only an Embed path maps to a column: its fields
 * are joined with "__". Anything past a "." lives in another table, so it
 * never has a column name of its own here.
 *
 * A field name never starts with "_" and never holds "__"; every name the
 * system adds to a table holding fields starts with "_". So the two never
 * collide, whatever a field is called.
 */
final class ColumnNames
{
    public const EMBED = '__';

    /** A level row's, or a side table row's, entity id. */
    public const ID = '_id';

    /** The owner's id, copied onto a level row for unique groups; a Collection item's owner. */
    public const OWNER = '_owner';

    /** The owning field's path, copied onto a level row for unique groups. */
    public const OWNER_FIELD = '_owner_field';

    /** A Collection item's index. */
    public const POSITION = '_position';

    /** A Scalar, ValueObject or Reference Collection item's value. */
    public const VALUE = '_value';

    /**
     * What a migration prefixes a column or table with while the old one
     * still holds data the new one needs: "_old__weight".
     */
    public const OLD = '_old__';

    /** The column $path's value lands on. Throws for a path with a "." in it. */
    public static function of(FieldPath $path): string {
        $name = (string) $path;
        if (str_contains($name, FieldPath::TRAVERSE)) {
            throw new InvalidArgumentException(sprintf('"%s" crosses into another table, so it has no column name.', $path));
        }

        return str_replace(FieldPath::EMBED, self::EMBED, $name);
    }

    /** The column holding an Owned Collection's item count: "_spares_count". */
    public static function countOf(FieldPath $path): string {
        return '_' . self::of($path) . '_count';
    }

    /** The table holding a non-owned Collection's items, declared on $table at $path: "car__wheels". */
    public static function collectionTable(string $table, FieldPath $path): string {
        return $table . self::EMBED . self::of($path);
    }

    /** The side table holding $table's touched flags: "_state__car", "_state__car__wheels". */
    public static function stateTable(string $table): string {
        return '_state' . self::EMBED . $table;
    }

    /** $name moved out of the way by a migration: "_old__weight". */
    public static function old(string $name): string {
        return self::OLD . $name;
    }
}
