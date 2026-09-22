<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\BaseProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\DigitalProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\PremiumDigitalProduct;

final class PrototypeShapeInheritanceTest extends TestCase
{
    public function testChainOfClassIsJustItselfWithNoNativeParent(): void
    {
        self::assertSame([BaseProduct::class], PrototypeShape::chainOfClass(BaseProduct::class));
    }

    public function testChainOfClassWalksEveryLevelBaseFirst(): void
    {
        self::assertSame(
            [BaseProduct::class, DigitalProduct::class, PremiumDigitalProduct::class],
            PrototypeShape::chainOfClass(PremiumDigitalProduct::class),
        );
    }

    public function testOwnFieldsOfClassOnlyReturnsFieldsDeclaredAtThatLevel(): void
    {
        $ownFields = PrototypeShape::ownFieldsOfClass(DigitalProduct::class);

        self::assertCount(1, $ownFields);
        self::assertSame('downloadUrl', $ownFields[0]->name);
    }

    public function testOfClassConcatenatesEveryLevelsOwnFields(): void
    {
        $names = array_map(
            static fn ($field) => $field->name,
            PrototypeShape::ofClass(PremiumDigitalProduct::class),
        );

        self::assertSame(['sku', 'name', 'downloadUrl', 'bonusContentCount'], $names);
    }
}
