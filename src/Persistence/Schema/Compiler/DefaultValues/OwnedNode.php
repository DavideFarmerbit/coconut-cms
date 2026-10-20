<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\DefaultValues;

use InvalidArgumentException;

/**
 * What an OwningReference holds: the object's concrete class, which can be
 * any subclass of the field's declared target, and that object's own field
 * values, read through that concrete class.
 */
final readonly class OwnedNode implements ValueNode
{
    /** @param class-string $class */
    public function __construct(
        public string $class,
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
