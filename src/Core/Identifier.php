<?php

namespace XyloIsCoding\CoconutCms\Core;

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
        return new self($namespace, $key);
    }

    public function __toString(): string
    {
        return "{$this->namespace}:{$this->key}";
    }
}
