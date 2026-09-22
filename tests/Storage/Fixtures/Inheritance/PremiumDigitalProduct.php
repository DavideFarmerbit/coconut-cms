<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** A third level, to prove the chain isn't hardcoded to depth two. */
final readonly class PremiumDigitalProduct extends DigitalProduct
{
    public function __construct(
        string $sku,
        string $name,
        string $downloadUrl,
        #[Field(queryable: true)]
        public int $bonusContentCount,
    ) {
        parent::__construct($sku, $name, $downloadUrl);
    }
}
