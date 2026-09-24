<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Product;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation\BaseTicket;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation\EndAfterStartValidator;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation\PriorityInRangeValidator;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation\PriorityTicket;

final class PrototypeShapePrototypeValidatorTest extends TestCase
{
    public function testOwnPrototypeValidatorsReadsThisLevelsAttribute(): void
    {
        $validators = PrototypeShape::ownPrototypeValidatorsOfClass(BaseTicket::class);

        self::assertCount(1, $validators);
        self::assertInstanceOf(EndAfterStartValidator::class, $validators[0]);
    }

    public function testAClassWithNoPrototypeValidationAttributeHasNone(): void
    {
        self::assertSame([], PrototypeShape::ownPrototypeValidatorsOfClass(Product::class));
    }

    public function testPrototypeValidatorsOfClassConcatenatesEveryChainLevelsOwnValidators(): void
    {
        $validators = PrototypeShape::prototypeValidatorsOfClass(PriorityTicket::class);

        self::assertCount(2, $validators);
        self::assertInstanceOf(EndAfterStartValidator::class, $validators[0]);
        self::assertInstanceOf(PriorityInRangeValidator::class, $validators[1]);
    }
}
