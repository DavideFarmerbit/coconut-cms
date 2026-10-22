<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use BackedEnum;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;

/**
 * A name-to-class registry of backed enums, so an editor-created field can
 * pick one by name instead of by PHP class reference, and a stored schema
 * names it by identifier, never by class.
 */
final class EnumRegistry
{
    /** @var array<string, class-string<BackedEnum>> */
    private array $enums = [];

    /** @param class-string<BackedEnum> $class */
    public function register(Identifier $id, string $class): void {
        $key = (string) $id;

        if (isset($this->enums[$key])) {
            throw new RuntimeException("Enum \"{$key}\" is already registered.");
        }

        if (!is_subclass_of($class, BackedEnum::class)) {
            throw new RuntimeException("\"{$class}\" is not a backed enum.");
        }

        $this->enums[$key] = $class;
    }

    /**
     * The identifier $class was registered under: how a stored schema names an
     * enum, so renaming or moving the enum doesn't break what's stored.
     * @param class-string<BackedEnum> $class
     */
    public function identifierOf(string $class): Identifier {
        $key = array_search($class, $this->enums, true);
        if ($key === false) {
            throw new RuntimeException("\"{$class}\" was never registered, so it has no identifier to be stored under.");
        }

        return Identifier::fromString($key);
    }

    /** @return class-string<BackedEnum> */
    public function resolve(Identifier $id): string {
        $key = (string) $id;

        if (!isset($this->enums[$key])) {
            throw new RuntimeException("Unknown enum \"{$key}\": nothing was registered under it.");
        }

        return $this->enums[$key];
    }
}
