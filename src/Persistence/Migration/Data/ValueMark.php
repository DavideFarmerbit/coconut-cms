<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

/**
 * What a value is while it moves, deciding whether it's touched once written:
 * a touched value stays touched unless it now equals its default, a converted
 * one is touched only where it differs from it, and an untouched one follows
 * whatever the new default is.
 */
enum ValueMark
{
    case Touched;
    case Untouched;
    case Converted;

    /** Whether a converter or initializer sees it as following its old default. */
    public function isDefault(): bool {
        return $this === self::Untouched;
    }
}
