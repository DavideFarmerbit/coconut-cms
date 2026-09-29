<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar;

use XyloIsCoding\CoconutCms\Storage\Attributes\Field;

/** Address before it gained a queryable `city` field, simulating a pre-migration shape. */
final readonly class AddressBeforeCityField
{
    public function __construct(
        #[Field]
        public string $street,
    ) {
    }
}
