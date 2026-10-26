<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use InvalidArgumentException;

/**
 * A read-only view of one entity's values, whatever its type's source: a
 * native class or the editor. Validators receive one; code that only reads
 * can take one. Paths are FieldPaths from this entity: "name",
 * "chassis:weight" into an Embed, "engine.horsepower" into an owned object,
 * "spares.0" to a Collection's item.
 */
interface EntityView
{
    /** The concrete type's identifier: "default:turbo_engine". */
    public function type(): string;

    /** Whether this entity is of $type, or of a type extending it. */
    public function isA(string $type): bool;

    /** Whether $path leads to a value of this entity. */
    public function has(string $path): bool;

    /**
     * The value at $path, as a property holds it: a scalar, an enum case, a
     * DateTimeImmutable, a value object; an Embed or an owned object as
     * another EntityView, or null for an empty slot; a Collection as a list
     * of its items in the same forms; a Reference as the id of the entity it
     * points at, or null. Throws when $path leads to no value.
     * @throws InvalidArgumentException
     */
    public function get(string $path): mixed;
}
