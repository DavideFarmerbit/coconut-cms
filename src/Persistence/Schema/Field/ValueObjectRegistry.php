<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;

/**
 * A name-to-class registry of value object classes, so an editor-created field
 * can pick one by name instead of by PHP class reference, and a stored schema
 * names it by identifier, never by class.
 */
final class ValueObjectRegistry
{
    /** @var array<string, class-string<StorableValue>> */
    private array $valueObjects = [];

    /** @param class-string<StorableValue> $class */
    public function register(Identifier $id, string $class): void {
        $key = (string) $id;

        if (isset($this->valueObjects[$key])) {
            throw new RuntimeException("Value object \"{$key}\" is already registered.");
        }

        if (!is_subclass_of($class, StorableValue::class)) {
            throw new RuntimeException("\"{$class}\" does not implement " . StorableValue::class . '.');
        }

        $this->valueObjects[$key] = $class;
    }

    /**
     * The identifier $class was registered under: how a stored schema names a
     * value object, so renaming or moving the class doesn't break what's stored.
     * @param class-string<StorableValue> $class
     */
    public function identifierOf(string $class): Identifier {
        $key = array_search($class, $this->valueObjects, true);
        if ($key === false) {
            throw new RuntimeException("\"{$class}\" was never registered, so it has no identifier to be stored under.");
        }

        return Identifier::fromString($key);
    }

    /** @return class-string<StorableValue> */
    public function resolve(Identifier $id): string {
        $key = (string) $id;

        if (!isset($this->valueObjects[$key])) {
            throw new RuntimeException("Unknown value object \"{$key}\": nothing was registered under it.");
        }

        return $this->valueObjects[$key];
    }
}
