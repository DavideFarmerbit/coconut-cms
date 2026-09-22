<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** A constructor parameter with no #[Field] must be skipped, not described. */
final readonly class WithUnannotatedProperty
{
    public function __construct(
        #[Field]
        public string $name,
        public string $internal,
    ) {
    }
}
