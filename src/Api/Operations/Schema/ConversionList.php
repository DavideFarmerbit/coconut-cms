<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;

/** What schema.conversions gives: the converters and initializers that fit, each in the order registered. */
final readonly class ConversionList implements OperationResult
{
    /**
     * @param list<ConversionDescription> $converters For a retype.
     * @param list<ConversionDescription> $initializers For a field added or moved up.
     * @param list<ConversionDescription> $levelInitializers For the levels a reparent gains.
     */
    public function __construct(
        public array $converters,
        public array $initializers,
        public array $levelInitializers,
    ) {
    }
}
