<?php

namespace XyloIsCoding\CoconutCms\Api\Error;

use RuntimeException;

/**
 * A schema save refused before it changed anything: the policy blocks a
 * break, the breaks weren't accepted, or entries can't move. Its details
 * say which, and list them, the way schema.preview does.
 */
final class SchemaSaveRefused extends RuntimeException
{
    /** @param array<string, mixed> $details "reason", then the breaks, the blocked ones, or the failures. */
    public function __construct(
        string $message,
        public readonly array $details,
    ) {
        parent::__construct($message);
    }
}
