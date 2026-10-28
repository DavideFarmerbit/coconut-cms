<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** One sort of entities.query: by a path, one way. */
final readonly class SortInput implements OperationInput
{
    /** @param string $path The field's path, as a condition names it. */
    public function __construct(
        public string $path,
        public SortDirection $direction = SortDirection::Ascending,
    ) {
    }
}
