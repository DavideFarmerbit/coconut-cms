<?php

namespace XyloIsCoding\CoconutCms\Persistence\Parameters;

use Attribute;

/**
 * Marks a constructor's string parameter holding an enum class: its
 * parameters name the enum by its registered identifier, never by class, and
 * the registry turns one into the other.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RegisteredEnum
{
}
