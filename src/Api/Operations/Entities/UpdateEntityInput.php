<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** entities.update's input: which entity, and what changes. */
final readonly class UpdateEntityInput implements OperationInput
{
    /**
     * @param string $id A standalone entity's id.
     * @param array<string, mixed> $changes Values by path, in stored form:
     *        see NativeEditor for each kind's form.
     */
    public function __construct(
        public string $id,
        public array $changes,
    ) {
    }
}
