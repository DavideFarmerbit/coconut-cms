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
 *         ->orderBy('price', Order::Descending)
 *         ->limit(20)
 *         ->after($cursor)
 *         ->page();
 *
 * A field is named by its path, the way migrations name it: "price",
 * "chassis:weight" into an Embed. Only fields of the queried type and its
 * ancestors are known. Owned entities are never listed on their own: they're
 * reached through their owner.
 */
final readonly class Query
{
    /**
     * @param string $identifier The queried type.
     * @param list<array{string, Condition}> $conditions Each with the path it applies to; all must hold.
     * @param list<array{string, Order}> $orders Each with the path it sorts by, first first.
     */
    public function __construct(
        private QueryRunner $runner,
        public string $identifier,
        public array $conditions = [],
        public array $orders = [],
        public ?int $limit = null,
        public ?string $cursor = null,
    ) {
    }

    /** Only entities whose value at $path meets $condition: every where() must hold. */
    public function where(string $path, Condition $condition): self {
        return new self($this->runner, $this->identifier, [...$this->conditions, [$path, $condition]], $this->orders, $this->limit, $this->cursor);
    }

    /** Sorted by $path, after any orderBy() before it; ties go by id, oldest first. */
    public function orderBy(string $path, Order $order = Order::Ascending): self {
        return new self($this->runner, $this->identifier, $this->conditions, [...$this->orders, [$path, $order]], $this->limit, $this->cursor);
    }

    /** At most $count entities a page. page() needs one. */
    public function limit(int $count): self {
        if ($count < 1) {
            throw new InvalidArgumentException(sprintf('A page holds at least one entity, not %d.', $count));
        }

        return new self($this->runner, $this->identifier, $this->conditions, $this->orders, $count, $this->cursor);
    }

    /** Starting after the page $cursor ended; null starts at the first. */
    public function after(?string $cursor): self {
        return new self($this->runner, $this->identifier, $this->conditions, $this->orders, $this->limit, $cursor);
    }

    /** The page this query asks for, its entities loaded. */
    public function page(): Page {
        return $this->runner->page($this);
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
}
