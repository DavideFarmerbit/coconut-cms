<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * One place an aggregate holds an object: its root, an owned object, or an
 * Embed, singular or a Collection item. Where a fixup on that object's type
 * applies.
 */
final readonly class Occurrence
{
    /**
     * @param FieldPath|null $path Where it sits: null for the root.
     * @param string $identifier Its type: the concrete one for the root or an
     *        owned object, the declared one for an Embed.
     * @param string $join How its own fields join to $path.
     */
    public function __construct(
        public ?FieldPath $path,
        public string $identifier,
        public OccurrenceKind $kind,
        public string $join,
    ) {
    }

    /** The path of its own field $name. */
    public function fieldPath(FieldName $name): FieldPath {
        return $this->pathOf(FieldPath::of($name));
    }

    /** The path of $relative, a path from this object. */
    public function pathOf(FieldPath $relative): FieldPath {
        return $this->path === null ? $relative : $this->path->append($this->join, $relative);
    }

    /** How many segments deep it sits: 0 for the root. */
    public function depth(): int {
        return $this->path === null ? 0 : substr_count((string) $this->path, FieldPath::EMBED) + substr_count((string) $this->path, FieldPath::TRAVERSE) + 1;
    }
}
