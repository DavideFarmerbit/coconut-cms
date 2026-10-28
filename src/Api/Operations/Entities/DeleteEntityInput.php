<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** entities.delete's input: which entity. */
final readonly class DeleteEntityInput implements OperationInput
{
    /** @param string $id A standalone entity's id. */
    public function __construct(
        public string $id,
    ) {
    }
}
