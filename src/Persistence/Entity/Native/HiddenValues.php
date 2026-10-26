<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Native;

use LogicException;
use WeakMap;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * What a load hid from its actor, by object: a field it may not read stays
 * uninitialized on the object, and its stored value waits here. A save finding
 * that property still uninitialized writes the value back as it was, so a
 * hidden value is never lost; one the code set is a write like any other. Kept
 * by object, never by path: an owned object moved elsewhere keeps its hidden
 * values. One per session: an object loaded for one actor is never another's.
 */
final class HiddenValues
{
    /** @var WeakMap<object, array<string, array{?ValueNode, array<string, string>}>> */
    private WeakMap $hidden;

    public function __construct() {
        $this->hidden = new WeakMap();
    }

    /**
     * Remembers that $object's field $name was hidden, holding $node.
     * @param array<string, string> $ownedIds The id of each owned object the
     *        value holds, by its path relative to the field: "" for the field
     *        itself, ".1" for an item, ":spare" through an Embed.
     */
    public function hide(object $object, string $name, ?ValueNode $node, array $ownedIds): void {
        $fields = $this->hidden[$object] ?? [];
        $fields[$name] = [$node, $ownedIds];
        $this->hidden[$object] = $fields;
    }

    /**
     * The stored value of $object's field $name a load hid, and the ids of the
     * owned objects in it. Throws when no load hid it: the code left the
     * property uninitialized itself.
     * @return array{?ValueNode, array<string, string>}
     */
    public function of(object $object, string $name): array {
        return $this->hidden[$object][$name] ?? throw new LogicException(sprintf(
            '%s::$%s is uninitialized, and no load hid it: give it a value before saving.',
            $object::class,
            $name,
        ));
    }
}
