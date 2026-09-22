<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** An embedded value object fixture: one queryable field, one blob-only field. */
final readonly class Address
{
    public function __construct(
        #[Field(queryable: true)]
        public string $city,
        #[Field]
        public string $street,
    ) {
    }
}
