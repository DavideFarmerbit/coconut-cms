<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Error\NotFound;
use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;

/**
 * One standalone entity, native or editor-created, as the caller may read
 * it. An owned entity is read through its owner: asked for by its own id,
 * it's not found.
 */
#[Operation('coconut-cms:entities.get')]
final readonly class GetEntity
{
    public function run(GetEntityInput $input, OperationContext $context): EntityData {
        $record = $context->session->read($input->id) ?? throw new NotFound('entity', $input->id);

        return EntityData::of($record);
    }
}
