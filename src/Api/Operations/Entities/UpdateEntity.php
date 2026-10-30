<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Error\NotFound;
use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\InvalidChange;

/**
 * Changes a standalone entity of any type by path, through its native
 * view's setters and afterEdit(), then saves it through the same permission and validation
 * rules as any save. Gives it back as entities.get reads it. An owned
 * entity is changed through its owner's paths: asked for by its own id,
 * it's not found.
 */
#[Operation('coconut-cms:entities.update')]
final readonly class UpdateEntity
{
    public function run(UpdateEntityInput $input, OperationContext $context): EntityData {
        try {
            $updated = $context->session->update($input->id, $input->changes);
        } catch (InvalidChange $invalid) {
            throw Changes::invalid($invalid);
        }

        if (!$updated) {
            throw new NotFound('entity', $input->id);
        }

        return EntityData::of($context->session->read($input->id) ?? throw new NotFound('entity', $input->id));
    }
}
