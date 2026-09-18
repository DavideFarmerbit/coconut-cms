<?php

namespace XyloIsCoding\CoconutCms\Core\Error;

/**
 * Assembled fresh per error occurrence: a short id to correlate what the client sees with what
 * got logged, plus whatever request info is available. Read from $_SERVER directly (not the
 * Routing package's own Request) since a failure can happen before routing ever runs.
 */
final readonly class ErrorContext
{
    public function __construct(
        public string $referenceId,
        public ?string $requestMethod,
        public ?string $requestUri,
    ) {
    }

    public static function capture(): self
    {
        return new self(
            referenceId: bin2hex(random_bytes(4)),
            requestMethod: $_SERVER['REQUEST_METHOD'] ?? null,
            requestUri: $_SERVER['REQUEST_URI'] ?? null,
        );
    }
}
