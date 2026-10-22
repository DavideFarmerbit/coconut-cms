<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Where one inheritance level's own fields live: its table, its touched-state
 * side table, and the Collection tables its fields need. An entity's full row
 * is every level of its chain, joined on id.
 */
final readonly class LevelLayout
{
    /**
     * @param string $identifier The entity type whose own level this is.
     * @param list<ValueColumn> $columns Every value stored on $table itself.
     * @param list<OwnedLayout> $owned
     * @param list<CollectionLayout> $collections
     * @param list<list<string>> $uniqueIndexes Column names, owner and owner_field included.
     */
    public function __construct(
        public string $identifier,
        public string $table,
        public string $stateTable,
        public array $columns,
        public array $owned,
        public array $collections,
        public array $uniqueIndexes,
    ) {
    }

    /**
     * One touched flag per path an instance can hold at this level: every
     * value column, and the bare path of every OwningReference and every
     * Collection, which flags the relationship as initialized.
     * @return array<string, string> Column name, keyed by path.
     */
    public function stateColumns(): array {
        $paths = [
            ...array_map(static fn (ValueColumn $column): FieldPath => $column->path, $this->columns),
            ...array_map(static fn (OwnedLayout $owned): FieldPath => $owned->path, $this->owned),
            ...array_map(static fn (CollectionLayout $collection): FieldPath => $collection->path, $this->collections),
        ];

        $columns = [];
        foreach ($paths as $path) {
            $columns[(string) $path] = ColumnNames::of($path);
        }

        return $columns;
    }
}
