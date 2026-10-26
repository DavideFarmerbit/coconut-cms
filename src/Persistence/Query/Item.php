<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/**
 * What one item of a Collection must meet, for whereHas() and
 * whereDoesntHave(). Immutable, built the way a query is, never a closure:
 *
 *     Item::where('horsepower', Is::greaterThan(200))->where('turbo.boost', Is::equalTo(5))
 *     Item::is(Is::equalTo('red'))
 *
 * Every condition holds for the same item. Paths are the item's own: an
 * Embed item's fields, an owned item's, a Reference item's target's, each
 * able to step on through a relationship. An item that is a value itself, a
 * Scalar or a ValueObject, is tested with is().
 */
final readonly class Item
{
    /**
     * @param list<array{string, Condition}> $conditions Each with the path it applies to.
     * @param Condition|null $itself What the item's own value must meet.
     * @param list<CollectionCondition> $collections Conditions on the item's own Collections.
     */
    private function __construct(
        public array $conditions = [],
        public ?Condition $itself = null,
        public array $collections = [],
    ) {
    }

    /** Any item at all. */
    public static function any(): self {
        return new self();
    }

    /** An item whose value at $path meets $condition. */
    public static function where(string $path, Condition $condition): self {
        return (new self())->andWhere($path, $condition);
    }

    /** An item whose own value meets $condition: for a Scalar, ValueObject or Reference item. */
    public static function is(Condition $condition): self {
        return new self(itself: $condition);
    }

    /** This item, its value at $path meeting $condition too. */
    public function andWhere(string $path, Condition $condition): self {
        return new self([...$this->conditions, [$path, $condition]], $this->itself, $this->collections);
    }

    /** This item, at least one item of its own Collection at $path meeting $item. */
    public function whereHas(string $path, ?Item $item = null): self {
        return new self($this->conditions, $this->itself, [...$this->collections, new CollectionCondition(CollectionTest::Has, $path, $item)]);
    }

    /** This item, no item of its own Collection at $path meeting $item. */
    public function whereDoesntHave(string $path, ?Item $item = null): self {
        return new self($this->conditions, $this->itself, [...$this->collections, new CollectionCondition(CollectionTest::DoesntHave, $path, $item)]);
    }

    /** This item, the length of its own Collection at $path meeting $count. */
    public function whereCount(string $path, Condition $count): self {
        return new self($this->conditions, $this->itself, [...$this->collections, new CollectionCondition(CollectionTest::Count, $path, count: $count)]);
    }
}
