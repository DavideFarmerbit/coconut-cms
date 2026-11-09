<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax;

use InvalidArgumentException;

/** Text that isn't the change language, with where it went wrong. */
final class SyntaxError extends InvalidArgumentException
{
    /**
     * @param int $textLine The line in the text, from 1. Not Exception's $line, the PHP line.
     * @param int $textColumn The column in the text, from 1.
     */
    public function __construct(
        string $message,
        public readonly int $textLine,
        public readonly int $textColumn,
    ) {
        parent::__construct(sprintf('Line %d, column %d: %s', $textLine, $textColumn, $message));
    }
}
