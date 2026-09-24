<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation;

use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Field\PrototypeValidation;

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
