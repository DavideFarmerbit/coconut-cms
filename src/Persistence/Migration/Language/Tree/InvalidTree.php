<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

use InvalidArgumentException;

/** JSON that isn't a stored tree, with where it went wrong. */
final class InvalidTree extends InvalidArgumentException
{
    /** @param string $at A JSON pointer: `/steps/0/to`. */
    public static function at(string $at, string $message): self {
        return new self(sprintf('At %s: %s', $at === '' ? '/' : $at, $message));
    }
}
