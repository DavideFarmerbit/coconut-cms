<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** One condition of entities.query: what the value at a path must be. */
final readonly class ConditionInput implements OperationInput
{
    /**
     * @param string $path The field's path, the way a query names it:
     *        "price", "chassis:weight", "maker.name".
     * @param list<mixed> $values In stored form, as entities.get gives them:
     *        an id for a Reference. None for isNull and isNotNull, one or
     *        more for in, one otherwise.
     */
    public function __construct(
        public string $path,
        public ConditionOperator $operator,
        public array $values = [],
    ) {
    }
}
