<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/**
 * schema.conversions's input: the field change to fit, if any. Each is what
 * a field holds, as types.get describes it, without a name.
 */
final readonly class ListConversionsInput implements OperationInput
{
    /**
     * @param mixed $from What the field holds now: only converters taking it are listed.
     * @param mixed $to What the field will hold: only converters giving it, and initializers filling it, are listed.
     */
    public function __construct(
        public mixed $from = null,
        public mixed $to = null,
    ) {
    }
}
