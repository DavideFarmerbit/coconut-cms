<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation;

use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Field\PrototypeValidation;

/** The base level of a Class Table Inheritance chain, with its own cross-field rule. */
#[PrototypeValidation(validators: [new EndAfterStartValidator()])]
readonly class BaseTicket
{
    public function __construct(
        #[Field(queryable: true)]
        public int $startDay,
        #[Field(queryable: true)]
        public int $endDay,
    ) {
    }
}
