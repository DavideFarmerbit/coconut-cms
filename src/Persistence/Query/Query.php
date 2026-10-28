<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

use InvalidArgumentException;

/**
 * A query over one entity type: its standalone entities and its subtypes',
 * filtered, sorted, a page at a time. Immutable: each call gives a new one.
 * Get one with Persistence::query():
 *
 *     $persistence->query(Car::class)
 *         ->where('chassis:weight', Is::greaterThan(1000))
 *         ->whereHas('spares', Item::where('horsepower', Is::greaterThan(200)))
 *         ->whereCount('tags', Is::greaterThanOrEqualTo(3))
 *         ->orderBy('price', Order::Descending)
 *         ->limit(20)
 *         ->after($cursor)
 *         ->page();
 *
 * A field is named by its path, the way migrations name it: "price",
 * "chassis:weight" into an Embed, "maker.name" through a relationship. Only
 * fields of the queried type and its ancestors are known. Owned entities are
 * never listed on their own: they're reached through their owner.
 */
final readonly class Query
{
    /**
     * @param string $identifier The queried type.
     * @param list<array{string, Condition}> $conditions Each with the path it applies to; all must hold.
     * @param list<array{string, Order}> $orders Each with the path it sorts by, first first.
     * @param list<CollectionCondition> $collections All must hold.
     */
    public function __construct(
        private QueryRunner $runner,
        public string $identifier,
        public array $conditions = [],
        public array $orders = [],
        public ?int $limit = null,
        public ?string $cursor = null,
        public array $collections = [],
    ) {
    }

    /** Only entities whose value at $path meets $condition: every where() must hold. */
    public function where(string $path, Condition $condition): self {
        return $this->with(conditions: [...$this->conditions, [$path, $condition]]);
    }

    /**
     * Only entities with at least one item of the Collection at $path meeting
     * $item; with any item at all when $item is null.
     */
    public function whereHas(string $path, ?Item $item = null): self {
        return $this->with(collections: [...$this->collections, new CollectionCondition(CollectionTest::Has, $path, $item)]);
    }

    /**
     * Only entities with no item of the Collection at $path meeting $item;
     * with an empty Collection when $item is null.
     */
    public function whereDoesntHave(string $path, ?Item $item = null): self {
        return $this->with(collections: [...$this->collections, new CollectionCondition(CollectionTest::DoesntHave, $path, $item)]);
    }

    /**
     * Only entities whose Collection at $path has a length meeting $count:
     * its length as a load shows it, an Owned Collection's empty slots
     * included.
     */
    public function whereCount(string $path, Condition $count): self {
        return $this->with(collections: [...$this->collections, new CollectionCondition(CollectionTest::Count, $path, count: $count)]);
    }

    /** Sorted by $path, after any orderBy() before it; ties go by id, oldest first. */
    public function orderBy(string $path, Order $order = Order::Ascending): self {
        return $this->with(orders: [...$this->orders, [$path, $order]]);
    }

    /** At most $count entities a page. page() needs one. */
    public function limit(int $count): self {
        if ($count < 1) {
            throw new InvalidArgumentException(sprintf('A page holds at least one entity, not %d.', $count));
        }

        return $this->with(limit: $count);
    }

    /** Starting after the page $cursor ended; null starts at the first. */
    public function after(?string $cursor): self {
        return new self($this->runner, $this->identifier, $this->conditions, $this->orders, $this->limit, $cursor, $this->collections);
    }

    /** The page this query asks for, its entities loaded. */
    public function page(): Page {
        return $this->runner->page($this);
    }

    /** The page this query asks for, as ids: nothing is loaded. */
    public function idPage(): IdPage {
        return $this->runner->idPage($this);
    }

    /**
     * The ids of what it matches, in order, from its cursor on, at most its
     * limit when it has one. Nothing is loaded.
     * @return list<string>
     */
    public function ids(): array {
        return $this->runner->ids($this);
    }

    /** How many entities it matches, whatever its limit and cursor. */
    public function count(): int {
        return $this->runner->count($this);
    }

    /**
     * This query with whatever is given replaced.
     * @param list<array{string, Condition}>|null $conditions
     * @param list<array{string, Order}>|null $orders
     * @param list<CollectionCondition>|null $collections
     */
    private function with(?array $conditions = null, ?array $orders = null, ?int $limit = null, ?array $collections = null): self {
        return new self(
            $this->runner,
            $this->identifier,
            $conditions ?? $this->conditions,
            $orders ?? $this->orders,
            $limit ?? $this->limit,
            $this->cursor,
            $collections ?? $this->collections,
        );
    }
}
