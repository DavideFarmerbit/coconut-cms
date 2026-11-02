<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;

/** What schema.values gives: the enums and value objects registered, each in the order registered. */
final readonly class ValueList implements OperationResult
{
    /**
     * @param list<EnumDescription> $enums
     * @param list<string> $valueObjects Each by identifier.
     */
    public function __construct(
        public array $enums,
        public array $valueObjects,
    ) {
    }
}
