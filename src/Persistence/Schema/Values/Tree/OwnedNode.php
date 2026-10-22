<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree;

use InvalidArgumentException;

/**
 * What an OwningReference holds: the owned object's concrete identifier,
 * which can be any descendant of the field's declared target, and that
 * object's own field values, read through that concrete identifier.
 */
final readonly class OwnedNode implements ValueNode
{
    public function __construct(
        public string $identifier,
        public ObjectNode $fields,
    ) {
    }

    /** $node itself, or null for no node at all. Throws if $node isn't an OwnedNode. */
    public static function from(?ValueNode $node): ?self {
        if ($node !== null && !$node instanceof self) {
            throw new InvalidArgumentException(sprintf('Expected an OwnedNode, got %s.', $node::class));
        }

        return $node;
    }
}
