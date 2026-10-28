<?php

namespace XyloIsCoding\CoconutCms\Api\Operation;

use XyloIsCoding\CoconutCms\Core\Identifier;

/** One registered operation: its name, its class, and what it takes and returns. */
final readonly class OperationDefinition
{
    /**
     * @param class-string $class Marked #[Operation].
     * @param class-string<OperationInput> $input What run() takes.
     * @param class-string<OperationResult> $result What run() returns.
     */
    public function __construct(
        public Identifier $identifier,
        public string $class,
        public string $input,
        public string $result,
    ) {
    }
}
