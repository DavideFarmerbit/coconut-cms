<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;

final class UniqueGroupTest extends TestCase
{
    public function testHoldsItsFieldsInOrder(): void {
        $group = UniqueGroup::of(FieldName::of('sku'), FieldName::of('region'));

        self::assertEquals([FieldName::of('sku'), FieldName::of('region')], $group->fields());
        self::assertCount(2, $group);
    }

    public function testAnEmptyGroupThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        UniqueGroup::of();
    }
}
