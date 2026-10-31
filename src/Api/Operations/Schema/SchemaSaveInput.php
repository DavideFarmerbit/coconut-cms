<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** schema.preview's and schema.save's input: the operations an admin made, on the types at one revision. */
final readonly class SchemaSaveInput implements OperationInput
{
    /**
     * @param int $revision The schema revision types.list gave when the admin started.
     * @param list<mixed> $operations Each as OperationForm reads it, in the order the admin made them.
     * @param bool $acceptBreaks Whether the admin saw the breaks the preview listed, and accepts them.
     */
    public function __construct(
        public int $revision,
        public array $operations,
        public bool $acceptBreaks = false,
    ) {
    }
}
