<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

/**
 * Implemented by every value object class: a custom primitive type, stored as
 * exactly one string. A #[ValueObject] field's class is the property's own
 * declared type, so the class itself must know how to store and rebuild
 * itself. Two values are equal when they store as the same string.
 */
interface StorableValue
{
    /** The string this value is stored as: plain or JSON, the class's own choice. */
    public function toStorage(): string;

    /** Rebuilds a value from what toStorage() produced. */
    public static function fromStorage(string $stored): static;
}
