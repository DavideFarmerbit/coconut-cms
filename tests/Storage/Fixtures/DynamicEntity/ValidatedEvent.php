<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\DynamicEntity;

use XyloIsCoding\CoconutCms\Storage\Attributes\EditorExtensible;
use XyloIsCoding\CoconutCms\Storage\Attributes\Field;
use XyloIsCoding\CoconutCms\Storage\Attributes\PrototypeValidation;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation\EndAfterStartValidator;

/** A native class, extensible by admins, with its own cross-field rule an editor-created subclass of it should still be bound by. */
#[EditorExtensible]
#[PrototypeValidation(validators: [new EndAfterStartValidator()])]
readonly class ValidatedEvent
{
    public function __construct(
        #[Field(queryable: true)]
        public int $startDay,
        #[Field(queryable: true)]
        public int $endDay,
    ) {
    }
}
