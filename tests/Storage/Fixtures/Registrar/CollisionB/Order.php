<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar\CollisionB;

use XyloIsCoding\CoconutCms\Storage\Field\Field;

/** Shares its short class name with CollisionA\Order on purpose, to exercise the registrar's collision guard. */
final readonly class Order
{
    public function __construct(
        #[Field(queryable: true)]
        public string $reference,
    ) {
    }
}
