<?php

namespace XyloIsCoding\CoconutCms\Core;

use InvalidArgumentException;

/**
 * A service identifier: a namespace (who owns/defines the binding — a
 * package name, "app", a mod slug, ...) plus a key (what it is, e.g.
 * "assets.frontend"). Two independently-constructed Identifiers with the
 * same namespace and key refer to the same service; the namespace exists
 * so unrelated code picking the same key don't collide.
 */
final readonly class Identifier implements \Stringable
{
    private function __construct(
        public string $namespace,
        public string $key,
    ) {
    }

    public static function of(string $namespace, string $key): self {
        if (str_contains($namespace, ':') || str_contains($key, ':')) {
            throw new InvalidArgumentException('Identifier namespace and key must not contain ":".');
        }

        return new self($namespace, $key);
    }

    /** Parses what __toString() produced: "namespace:key". */
    public static function fromString(string $identifier): self {
        $parts = explode(':', $identifier);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(sprintf('"%s" is not a "namespace:key" identifier.', $identifier));
        }

        return self::of($parts[0], $parts[1]);
    }

    public function __toString(): string
    {
        return "{$this->namespace}:{$this->key}";
    }
}
