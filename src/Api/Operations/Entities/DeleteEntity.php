<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Error\NotFound;
use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\InvalidChange;

/**
 * Deletes a standalone native entity with everything it owns. An owned
 * entity is removed through its owner's paths: asked for by its own id,
 * it's not found.
 */
#[Operation('coconut-cms:entities.delete')]
final readonly class DeleteEntity
{
    public function run(DeleteEntityInput $input, OperationContext $context): EntityDeleted {
        try {
            $deleted = $context->session->deleteById($input->id);
        } catch (InvalidChange $invalid) {
            throw Changes::invalid($invalid);
        }

        return $deleted ? new EntityDeleted($input->id) : throw new NotFound('entity', $input->id);
    }
}
