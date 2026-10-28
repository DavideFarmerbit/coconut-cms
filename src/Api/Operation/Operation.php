<?php

namespace XyloIsCoding\CoconutCms\Api\Operation;

use Attribute;

/**
 * Marks a class as an API operation, named by an identifier, "namespace:key":
 *
 *     #[Operation('coconut-cms:entities.query')]
 *     final readonly class QueryEntities
 *     {
 *         public function run(QueryEntitiesInput $input, OperationContext $context): EntityPage { ... }
 *     }
 *
 * The class has a constructor with no required parameters, and one public
 * run() method taking its OperationInput and the OperationContext, and
 * returning its OperationResult. A module lists it in operations().
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Operation
{
    /** @param string $identifier "namespace:key": by convention, the declaring module's name is the namespace. */
    public function __construct(
        public string $identifier,
    ) {
    }
}
