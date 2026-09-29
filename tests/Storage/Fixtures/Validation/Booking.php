<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation;

use XyloIsCoding\CoconutCms\Storage\Attributes\Field;
use XyloIsCoding\CoconutCms\Storage\Attributes\PrototypeValidation;

/** A prototype-level cross-field rule, no single field's own descriptor could express this. */
#[PrototypeValidation(validators: [new EndAfterStartValidator()])]
final readonly class Booking
{
    public function __construct(
        #[Field(queryable: true)]
        public int $startDay,
        #[Field(queryable: true)]
        public int $endDay,
    ) {
    }
}
