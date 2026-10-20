<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\DefaultValues;

use InvalidArgumentException;

/**
 * One plain value: a Scalar, a Reference's raw value, or the string a
 * ValueObject stores as. Can hold null.
 */
final readonly class LeafNode implements ValueNode
{
    public function __construct(
        public mixed $value,
    ) {
    }

    /** $node's value, null for no node at all. Throws if $node isn't a LeafNode. */
    public static function valueOf(?ValueNode $node): mixed {
        if ($node !== null && !$node instanceof self) {
            throw new InvalidArgumentException(sprintf('Expected a LeafNode, got %s.', $node::class));
        }

        return $node?->value;
    }
}
