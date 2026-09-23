<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations;

use XyloIsCoding\CoconutCms\Storage\Field\Field;
use XyloIsCoding\CoconutCms\Storage\Field\Validator\RequiredValidator;

/** A Shared many-to-many collection target: several products can reference the same tag. */
final readonly class Tag
{
    public function __construct(
        #[Field(queryable: true, unique: true, validators: [new RequiredValidator()])]
        public string $name,
    ) {
    }
}
