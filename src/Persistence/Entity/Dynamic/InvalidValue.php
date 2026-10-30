<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic;

use InvalidArgumentException;

/**
 * Thrown by DynamicEntity::set() for a value its field can't hold: the wrong
 * kind, the wrong type, null where it may not be, an entity of a type the
 * field doesn't target. Thrown where the value is set, the way PHP refuses a
 * typed property's wrong value.
 */
final class InvalidValue extends InvalidArgumentException
{
    /**
     * @param string $path The path the value was set at, from the entity set() was called on.
     * @param string $reason Why it can't hold it.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('"%s" can\'t hold that: %s', $path, $reason));
    }

    /** The same, its path seen from the entity holding this one at $prefix, joined by $join. */
    public function under(string $prefix, string $join): self {
        return new self($prefix . $join . $this->path, $this->reason);
    }
}
