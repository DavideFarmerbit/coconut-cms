<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/**
 * A middle level: $sku and $name are forwarded to BaseProduct's own constructor, not
 * re-promoted here, so they stay declared on BaseProduct, not this class.
 */
readonly class DigitalProduct extends BaseProduct
{
    public function __construct(
        string $sku,
        string $name,
        #[Field(queryable: true)]
        public string $downloadUrl,
    ) {
        parent::__construct($sku, $name);
    }
}
