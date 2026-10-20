<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldValuesDiff;

final class FieldValuesDiffTest extends TestCase
{
    public function testBetweenSortsPathsIntoAddedRemovedAndChanged(): void {
        $diff = FieldValuesDiff::between(
            FieldValues::fromArray(['horsepower' => 300, 'torque' => 400, 'engine' => 'App\\Engine']),
            FieldValues::fromArray(['horsepower' => 500, 'engine' => 'App\\Engine', 'boost' => 2]),
        );

        self::assertEquals([FieldPath::fromString('boost')], $diff->added);
        self::assertEquals([FieldPath::fromString('torque')], $diff->removed);
        self::assertEquals([FieldPath::fromString('horsepower')], $diff->changed);
        self::assertFalse($diff->isEmpty());
    }

    public function testEqualValuesMakeAnEmptyDiff(): void {
        $values = FieldValues::fromArray(['engine' => null, 'tags.0' => 'a']);

        self::assertTrue(FieldValuesDiff::between($values, $values)->isEmpty());
    }

    public function testValuesCompareStrictly(): void {
        $diff = FieldValuesDiff::between(FieldValues::fromArray(['horsepower' => 10]), FieldValues::fromArray(['horsepower' => '10']));

        self::assertEquals([FieldPath::fromString('horsepower')], $diff->changed);
    }
}
