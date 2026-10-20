<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Marks a native property as a ValueObject field: a custom primitive type stored as
 * one string. The value object class is the property's own declared type, which
 * must implement StorableValue.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ValueObject
{
}
