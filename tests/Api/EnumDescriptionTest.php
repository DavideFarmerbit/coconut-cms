<?php

namespace XyloIsCoding\CoconutCms\Tests\Api;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Api\Operations\Schema\EnumDescription;

/** A hull's material: what the test describes. */
enum DescribedHull: string
{
    case Wood = 'wood';
    case Steel = 'steel';
}

/** An enum as schema.values lists it. */
final class EnumDescriptionTest extends TestCase
{
    public function testAnEnumIsListedWithItsValuesInOrder(): void {
        self::assertEquals(new EnumDescription('shop:hull', ['wood', 'steel']), EnumDescription::of('shop:hull', DescribedHull::class));
    }
}
