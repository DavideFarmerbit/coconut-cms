<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures;

use XyloIsCoding\CoconutCms\Storage\Field;

/** A class-typed #[Field] with neither #[Embed] nor #[Reference] must be rejected clearly. */
final readonly class WithUnmarkedClassTypedField
{
    public function __construct(
        #[Field]
        public Address $address,
    ) {
    }
}
