<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;

/** What schema.validators gives: the validator rules registered, each in the order registered. */
final readonly class ValidatorList implements OperationResult
{
    /**
     * @param list<RuleDescription> $fieldRules For a field: those fitting it, when one was given.
     * @param list<RuleDescription> $prototypeRules For a type, across its fields.
     */
    public function __construct(
        public array $fieldRules,
        public array $prototypeRules,
    ) {
    }
}
