<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;

/** What entities.delete deleted: the entity, with everything it owned. */
final readonly class EntityDeleted implements OperationResult
{
    public function __construct(
        public string $id,
    ) {
    }
}
