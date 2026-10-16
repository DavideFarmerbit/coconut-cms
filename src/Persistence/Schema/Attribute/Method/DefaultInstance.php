<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Method;

use Attribute;

/**
 * Marks the static, zero-argument factory method that produces this class's defaulted
 * instance.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class DefaultInstance
{
}
