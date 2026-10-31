<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use RuntimeException;

/**
 * An editor save's operation that can't apply to the types as they are when
 * it runs: it names a type or field that doesn't exist, takes a name already
 * taken, or changes a native type. The save is refused, naming it.
 */
final class InvalidOperation extends RuntimeException
{
    /** Which operation of the save, as sent, counting from 0; null when it's about the save as a whole. */
    public ?int $index = null;

    /** $refused, about the operation at $index of the save as sent. */
    public static function at(int $index, self $refused): self {
        $located = new self($refused->getMessage(), 0, $refused);
        $located->index = $index;

        return $located;
    }
}
