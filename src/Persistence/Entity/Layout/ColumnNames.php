<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Derives the real table and column names a FieldPath lands on. Only an Embed
 * path maps to a column: its fields are joined with "__". Anything past a "."
 * lives in another table, so it never has a column name of its own here.
 */
final class ColumnNames
{
    public const EMBED = '__';

    /** The column $path's value lands on. Throws for a path with a "." in it. */
    public static function of(FieldPath $path): string {
        $name = (string) $path;
        if (str_contains($name, FieldPath::TRAVERSE)) {
            throw new InvalidArgumentException(sprintf('"%s" crosses into another table, so it has no column name.', $path));
        }

        return str_replace(FieldPath::EMBED, self::EMBED, $name);
    }

    /** The column holding an Owned Collection's item count. */
    public static function countOf(FieldPath $path): string {
        return self::of($path) . '_count';
    }

    /** The table holding a non-owned Collection's items, declared on $table at $path. */
    public static function collectionTable(string $table, FieldPath $path): string {
        return $table . self::EMBED . self::of($path);
    }

    /** The side table holding $table's touched flags. */
    public static function stateTable(string $table): string {
        return $table . self::EMBED . 'state';
    }
}
