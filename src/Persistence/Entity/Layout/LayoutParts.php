<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Collects what LayoutBuilder finds while walking one table's fields, Embeds
 * included. Mutable, and local to one walk: either a level's table or a
 * Collection's item table.
 */
final class LayoutParts
{
    /** @var list<ValueColumn> */
    private array $columns = [];
    /** @var list<OwnedLayout> */
    private array $owned = [];
    /** @var list<CollectionLayout> */
    private array $collections = [];
    /** @var list<list<string>> */
    private array $uniqueIndexes = [];

    /**
     * @param list<string> $scopeColumns What every unique index starts with, so
     *        it never spans two owners.
     * @param FieldPath|null $item The Collection this walks the items of, or
     *        null for a level's own table.
     */
    private function __construct(
        public readonly string $table,
        private readonly array $scopeColumns,
        private readonly ?FieldPath $item,
    ) {
    }

    public static function forLevel(string $table): self {
        return new self($table, ['owner', 'owner_field'], null);
    }

    /** For the items of the Collection at $collection, stored on $table. */
    public static function forItems(string $table, FieldPath $collection): self {
        return new self($table, ['owner'], $collection);
    }

    public function addColumn(ValueColumn $column): void {
        $this->columns[] = $column;
    }

    /** Throws for a Collection's item, which SchemaCompiler already rejects. */
    public function addOwned(OwnedLayout $owned): void {
        $this->assertNotItem($owned->path);
        $this->owned[] = $owned;
    }

    /** Throws for a Collection's item, which SchemaCompiler already rejects. */
    public function addCollection(CollectionLayout $collection): void {
        $this->assertNotItem($collection->path);
        $this->collections[] = $collection;
    }

    /**
     * Adds a unique index over $columns, scoped to one owner.
     * @param list<string> $columns
     */
    public function addUniqueIndex(array $columns): void {
        $this->uniqueIndexes[] = [...$this->scopeColumns, ...$columns];
    }

    /**
     * The value columns stored at $path or under it.
     * @return list<ValueColumn>
     */
    public function columnsUnder(FieldPath $path): array {
        return array_values(array_filter(
            $this->columns,
            static fn (ValueColumn $column): bool => $column->path?->startsWith($path) ?? false,
        ));
    }

    /** @return list<ValueColumn> */
    public function columns(): array {
        return $this->columns;
    }

    /** @return list<OwnedLayout> */
    public function owned(): array {
        return $this->owned;
    }

    /** @return list<CollectionLayout> */
    public function collections(): array {
        return $this->collections;
    }

    /** @return list<list<string>> */
    public function uniqueIndexes(): array {
        return $this->uniqueIndexes;
    }

    private function assertNotItem(FieldPath $path): void {
        if ($this->item !== null) {
            throw new LogicException(sprintf('The items of "%s" hold "%s", which compiling should have rejected.', $this->item, $path));
        }
    }
}
