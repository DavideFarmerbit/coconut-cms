<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

/**
 * Implemented by every value object class: a custom primitive type, stored as
 * exactly one string. A #[ValueObject] field's class is the property's own
 * declared type, so the class itself must know how to store and rebuild
 * itself. Two values are equal when they store as the same string.
 * Stored through StorableValues::toStorage(), never toStorage() directly,
 * so indexable() is enforced.
 */
interface StorableValue
{
    /** The string this value is stored as: plain or JSON, the class's own choice. */
    public function toStorage(): string;

    /**
     * Whether toStorage() always fits in 255 characters. If so, the value is
     * stored as VARCHAR(255) and may be part of a unique group. If not, it's
     * stored as TEXT, which MySQL can't index whole.
     */
    public static function indexable(): bool;

    /** Rebuilds a value from what toStorage() produced. */
    public static function fromStorage(string $stored): static;
}
