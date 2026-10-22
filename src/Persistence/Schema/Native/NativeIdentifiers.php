<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use InvalidArgumentException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;

/**
 * The identifier a native entity class is stored under: the one its #[Entity]
 * declares, or "default:" and its short name, snake-cased. Stored content
 * names a type by this, never by its class, so moving a class to another
 * namespace changes nothing stored.
 */
final class NativeIdentifiers
{
    /** The namespace of every identifier derived from a short class name. */
    public const DEFAULT_NAMESPACE = 'default';

    /** @var array<class-string, string> */
    private static array $byClass = [];

    /**
     * $class's identifier, as "namespace:key". Throws when $class isn't
     * marked #[Entity], or declares a malformed identifier.
     * @param class-string $class
     */
    public static function of(string $class): string {
        if (isset(self::$byClass[$class])) {
            return self::$byClass[$class];
        }

        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Entity::class);
        if ($attributes === []) {
            throw new InvalidArgumentException(sprintf('%s must be marked #[Entity].', $class));
        }

        $declared = $attributes[0]->newInstance()->identifier;
        $identifier = $declared === null
            ? Identifier::of(self::DEFAULT_NAMESPACE, self::snakeCase($reflection->getShortName()))
            : Identifier::fromString($declared);

        return self::$byClass[$class] = (string) $identifier;
    }

    /**
     * The table an identifier's own level is stored in, when its class names
     * none: the key alone in the default namespace, the namespace and the key
     * otherwise, so two packages' keys never land on one table.
     */
    public static function tableOf(string $identifier): string {
        $parsed = Identifier::fromString($identifier);
        $table = $parsed->namespace === self::DEFAULT_NAMESPACE ? $parsed->key : $parsed->namespace . '_' . $parsed->key;

        return str_replace(['-', '.'], '_', $table);
    }

    /** Product -> product, BlogPost -> blog_post. */
    private static function snakeCase(string $shortClassName): string {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortClassName));
    }
}
