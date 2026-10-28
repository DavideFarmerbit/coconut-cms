<?php

namespace XyloIsCoding\CoconutCms\Tests\Api\Nested;

use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/** An input in another namespace: reached only through an import. */
final readonly class FixtureTag implements OperationInput
{
    public function __construct(
        public string $label,
    ) {
    }
}
