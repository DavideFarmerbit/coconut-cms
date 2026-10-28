<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Types;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** types.get's input: which type. */
final readonly class GetTypeInput implements OperationInput
{
    /** @param string $type Its identifier: "shop:car". */
    public function __construct(
        public string $type,
    ) {
    }
}
