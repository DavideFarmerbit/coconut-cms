<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree;

/**
 * One node of an object's values, as read off a default instance or a live
 * entity: a
 * LeafNode for a Scalar, ValueObject or Reference field, an ObjectNode for an
 * Embed, an OwnedNode for an OwningReference, a CollectionNode for a
 * Collection. An Embed or OwningReference holding nothing is null in the
 * node's place, never a node.
 */
interface ValueNode
{
}
