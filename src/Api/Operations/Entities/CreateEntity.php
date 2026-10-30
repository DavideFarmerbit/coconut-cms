<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Error\NotFound;
use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\InvalidChange;

/**
 * Creates a standalone entity of any type, native or editor-created, from
 * its defaults, changes applied by path through its native view's setters
 * and afterEdit(), then saved through
 * the same permission and validation rules as any save. Gives it back as
 * entities.get reads it.
 */
#[Operation('coconut-cms:entities.create')]
final readonly class CreateEntity
{
    public function run(CreateEntityInput $input, OperationContext $context): EntityData {
        if (!$context->session->schemas()->has($input->type)) {
            throw new NotFound('type', $input->type);
        }

        try {
            $id = $context->session->create($input->type, $input->changes);
        } catch (InvalidChange $invalid) {
            throw Changes::invalid($invalid);
        }

        return EntityData::of($context->session->read($id) ?? throw new NotFound('entity', $id));
    }
}
