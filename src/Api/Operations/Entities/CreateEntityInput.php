<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** entities.create's input: a type, and the changes to its defaults. */
final readonly class CreateEntityInput implements OperationInput
{
    /**
     * @param string $type The type of the entity created, native or editor-created: "shop:car".
     * @param array<string, mixed> $changes Values by path, in stored form:
     *        see StoredFormEditor for each kind's form.
     */
    public function __construct(
        public string $type,
        public array $changes = [],
    ) {
    }
}
