<?php

namespace XyloIsCoding\CoconutCms\Api\Error;

use RuntimeException;

/** Thrown by an operation when what it was asked about doesn't exist. */
final class NotFound extends RuntimeException
{
    /**
     * @param string $kind What's missing: "entity", "type", "operation".
     * @param string $key How it was named: an id, an identifier.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $key,
    ) {
        parent::__construct(sprintf('There is no %s "%s".', $kind, $key));
    }
}
