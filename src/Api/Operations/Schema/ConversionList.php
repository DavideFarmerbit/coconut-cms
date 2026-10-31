<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;

/** What schema.conversions gives: the converters and initializers that fit, each in the order registered. */
final readonly class ConversionList implements OperationResult
{
    /**
     * @param list<ConversionDescription> $converters
     * @param list<ConversionDescription> $initializers
     */
    public function __construct(
        public array $converters,
        public array $initializers,
    ) {
    }
}
