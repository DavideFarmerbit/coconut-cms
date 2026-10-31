<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use Attribute;

/**
 * Names a FieldConverter a module registers, so an editor save can pick it:
 * `#[Converter('shop:euro-price')]`. Its constructor's parameters are what
 * the save gives it, by name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Converter
{
    /** @param string $identifier "namespace:key", unique among every converter and initializer. */
    public function __construct(
        public string $identifier,
    ) {
    }
}
