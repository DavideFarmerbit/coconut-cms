<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/** Finds values in a tree of ValueNodes by path. */
final class ValueTrees
{
    /**
     * What $node holds at $path: through an Embed's fields, an owned object's
     * fields, a Collection's items. Null when nothing is held there.
     */
    public static function nodeAt(ValueNode $node, FieldPath $path): ?ValueNode {
        if ($node instanceof OwnedNode) {
            $node = $node->fields;
        }

        $head = $path->head();
        $next = match (true) {
            is_int($head) && $node instanceof CollectionNode => $node->items[$head] ?? null,
            $head instanceof FieldName && $node instanceof ObjectNode => $node->find($head),
            default => null,
        };
        $tail = $path->tail();

        return $next === null || $tail === null ? $next : self::nodeAt($next, $tail);
    }
}
