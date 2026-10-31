<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use Attribute;

/**
 * Marks a converter's or initializer's string parameter holding an enum
 * class: a save names the enum by its registered identifier, never by class,
 * and the registry turns one into the other.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RegisteredEnum
{
}
