<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** A nullable #[Field] type isn't supported yet, and must be rejected clearly. */
final readonly class WithNullableField
{
    public function __construct(
        #[Field]
        public ?string $name,
    ) {
    }
}
