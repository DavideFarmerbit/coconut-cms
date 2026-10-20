<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Value;

/**
 * One node of an object's default values, as a producer reads them: a
 * LeafNode for a Scalar, ValueObject or Reference field, an ObjectNode for an
 * Embed, an OwnedNode for an OwningReference, a CollectionNode for a
 * Collection. An Embed or OwningReference holding nothing is null in the
 * node's place, never a node.
 */
interface ValueNode
{
}
