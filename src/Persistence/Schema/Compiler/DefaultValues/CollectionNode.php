<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\DefaultValues;

use Countable;
use InvalidArgumentException;

/**
 * What a Collection holds: its items, in order, each following the rule for
 * the collection's own item kind.
 */
final readonly class CollectionNode implements ValueNode, Countable
{
    /** @var list<ValueNode|null> */
    public array $items;

    /** @param array<ValueNode|null> $items */
    public function __construct(array $items) {
        $this->items = array_values($items);
    }

    /** $node itself, or null for no node at all. Throws if $node isn't a CollectionNode. */
    public static function from(?ValueNode $node): ?self {
        if ($node !== null && !$node instanceof self) {
            throw new InvalidArgumentException(sprintf('Expected a CollectionNode, got %s.', $node::class));
        }

        return $node;
    }

    public function count(): int {
        return count($this->items);
    }
}
