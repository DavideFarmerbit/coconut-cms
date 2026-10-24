<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

use Attribute;

/**
 * The identifier a value object, a backed enum or a validator rule class is
 * stored under, as "namespace:key". Optional: without it, the class is stored
 * under "default:" and its short name, snake-cased, the same as an #[Entity]
 * without an identifier. A stored schema names the class by this, never by
 * the class itself.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class StoredAs
{
    public function __construct(
        public string $identifier,
    ) {
    }
}
