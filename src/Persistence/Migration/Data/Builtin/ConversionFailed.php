<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use RuntimeException;

/**
 * A built-in converter told to cancel met a value it can't convert. The
 * migration fails, naming the entity it was moving.
 */
final class ConversionFailed extends RuntimeException
{
    /** For $value, which $converter can't convert. */
    public static function of(string $converter, mixed $value): self {
        return new self(sprintf('%s can\'t convert %s.', $converter, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
}
