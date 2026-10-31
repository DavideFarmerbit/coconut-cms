<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

use Attribute;

/**
 * The identifier a backed enum is stored under: `#[EnumType('shop:color')]`.
 * Optional: without it, the enum is stored under "default:" and its short
 * name, snake-cased, the same as an #[Entity] without an identifier.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EnumType implements NamesStoredClass
{
    /** @param string $identifier "namespace:key". */
    public function __construct(
        public string $identifier,
    ) {
    }
}
