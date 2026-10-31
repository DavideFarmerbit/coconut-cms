<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use Attribute;

/**
 * Names a FieldInitializer a module registers, so an editor save can pick
 * it: `#[Initializer('shop:serial-from-power')]`. Its constructor's
 * parameters are what the save gives it, by name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Initializer
{
    /** @param string $identifier "namespace:key", unique among every converter and initializer. */
    public function __construct(
        public string $identifier,
    ) {
    }
}
