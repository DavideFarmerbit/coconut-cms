<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Uniqueness;

use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\CollectionLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;

/**
 * One unique index a migration's tables will have: a level's, or a
 * Collection's, its scope columns first. What UniquenessCheck checks the
 * content against before anything changes.
 */
final readonly class UniqueIndex
{
    /**
     * @param string $type The entity type declaring the table.
     * @param list<string> $columns Every column, the scope's first.
     * @param list<string> $valueColumns The group's own columns, without the scope's.
     * @param string $entityColumn The column naming the entity a row is of: its id on a level, its owner's on a Collection.
     * @param bool $new Whether the tables don't have it yet.
     */
    public function __construct(
        public string $type,
        public string $table,
        public array $columns,
        public array $valueColumns,
        public string $entityColumn,
        public bool $new,
    ) {
    }

    /**
     * Every unique index $target's tables have, each new unless $stored's
     * have it too.
     * @param list<LevelLayout> $target
     * @param list<LevelLayout> $stored
     * @return list<self>
     */
    public static function allOf(array $target, array $stored): array {
        $existing = [];
        foreach (self::listed($stored) as [, $table, $columns]) {
            $existing[$table . '|' . implode(',', $columns)] = true;
        }

        $indexes = [];
        foreach (self::listed($target) as [$type, $table, $columns, $entityColumn, $scope]) {
            $indexes[] = new self($type, $table, $columns, array_values(array_slice($columns, $scope)), $entityColumn, !isset($existing[$table . '|' . implode(',', $columns)]));
        }

        return $indexes;
    }

    /**
     * @param list<LevelLayout> $levels
     * @return list<array{string, string, list<string>, string, int}> Each index's type, table, columns, entity column, and how many scope columns lead it.
     */
    private static function listed(array $levels): array {
        $listed = [];
        foreach ($levels as $level) {
            foreach ($level->uniqueIndexes as $columns) {
                $listed[] = [$level->identifier, $level->table, $columns, ColumnNames::ID, 2];
            }

            foreach ($level->collections as $collection) {
                array_push($listed, ...self::collectionIndexes($level, $collection));
            }
        }

        return $listed;
    }

    /** @return list<array{string, string, list<string>, string, int}> */
    private static function collectionIndexes(LevelLayout $level, CollectionLayout $collection): array {
        return array_map(
            static fn (array $columns): array => [$level->identifier, $collection->table, $columns, ColumnNames::OWNER, 1],
            $collection->uniqueIndexes,
        );
    }
}
