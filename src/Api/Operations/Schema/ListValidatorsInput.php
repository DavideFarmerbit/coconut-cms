<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/**
 * schema.validators's input: the field to fit, if any, as types.get
 * describes it, without a name.
 */
final readonly class ListValidatorsInput implements OperationInput
{
    /** @param mixed $field What the field holds: only field rules fitting it are listed. For the rules OnItems takes, give a Collection's item. */
    public function __construct(
        public mixed $field = null,
    ) {
    }
}
