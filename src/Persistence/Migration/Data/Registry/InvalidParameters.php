<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

use RuntimeException;

/**
 * Parameters a save gives a converter or initializer that don't fit its
 * constructor, or one no module registered. $parameter names the one at
 * fault, null for the converter or initializer itself.
 */
final class InvalidParameters extends RuntimeException
{
    public function __construct(
        public readonly ?string $parameter,
        string $message,
    ) {
        parent::__construct($message);
    }
}
