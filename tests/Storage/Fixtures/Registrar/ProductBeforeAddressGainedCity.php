<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar;

use XyloIsCoding\CoconutCms\Storage\Attributes\Embed;
use XyloIsCoding\CoconutCms\Storage\Attributes\Field;

/**
 * Same shape as Fixtures\Product, but embedding AddressBeforeCityField instead of the
 * real Address, simulating the table as it existed before Address gained `city`.
 */
final readonly class ProductBeforeAddressGainedCity
{
    public function __construct(
        #[Field(queryable: true, unique: true)]
        public string $sku,
        #[Field(queryable: true)]
        public string $name,
        #[Field]
        public string $description,
        #[Field]
        #[Embed]
        public AddressBeforeCityField $address,
        #[Field(queryable: true)]
        public bool $active,
    ) {
    }
}
