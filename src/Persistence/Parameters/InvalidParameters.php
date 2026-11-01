<?php

namespace XyloIsCoding\CoconutCms\Persistence\Parameters;

use RuntimeException;

/**
 * Parameters given to a converter, initializer or validator rule that don't
 * fit its constructor, or one no module registered. $parameter names the one
 * at fault, null for the class itself.
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
