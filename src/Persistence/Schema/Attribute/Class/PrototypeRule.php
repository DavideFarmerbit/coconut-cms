<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

use Attribute;

/**
 * The identifier a PrototypeValidatorRule class is stored under:
 * `#[PrototypeRule('shop:dates-in-order')]`. Optional: without it, the class is
 * stored under "default:" and its short name, snake-cased, the same as an
 * #[Entity] without an identifier.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PrototypeRule implements NamesStoredClass
{
    /** @param string $identifier "namespace:key". */
    public function __construct(
        public string $identifier,
    ) {
    }
}
