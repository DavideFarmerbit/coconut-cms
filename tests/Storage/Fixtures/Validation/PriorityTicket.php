<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation;

use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Field\PrototypeValidation;

/** A derived level with its own, independent cross-field rule, on top of the base level's. */
#[PrototypeValidation(validators: [new PriorityInRangeValidator()])]
final readonly class PriorityTicket extends BaseTicket
{
    public function __construct(
        int $startDay,
        int $endDay,
        #[Field(queryable: true)]
        public int $priority,
    ) {
        parent::__construct($startDay, $endDay);
    }
}
