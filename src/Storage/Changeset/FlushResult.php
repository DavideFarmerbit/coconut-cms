<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/** What a flushed Changeset produced: the operation id to use as the next expectedOperationId receipt, and which real id each TempId was assigned. */
final readonly class FlushResult
{
    /** @param array<string, string> $resolvedIds TempId id => the real id it was assigned */
    public function __construct(
        public string $operationId,
        public array $resolvedIds,
    ) {
    }
}
