<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/** A type's default instance moving: every value moves, only what the type sets itself is kept. */
final class DefaultInstanceValuesTest extends TestCase
{
    /** A boat setting its price and its chassis's weight, inheriting the rest. */
    private static function boat(): AggregateValues {
        return AggregateValues::ofDefaults('ed:boat', [
            'price' => 5,
            'color' => 'white',
            'chassis:weight' => 900,
            'chassis:code' => 'C',
            'engine' => 'ed:engine',
            'engine.horsepower' => 100,
        ], ['price', 'chassis:weight']);
    }

    private static function path(string $path): FieldPath {
        return FieldPath::fromString($path);
    }

    public function testWhatItSetsFollowsAMove(): void {
        $boat = self::boat();
        $boat->move(self::path('price'), self::path('cost'));

        self::assertSame(['cost' => 5, 'chassis:weight' => 900], $boat->ownValues());
    }

    public function testAConversionOfWhatItSetsIsKeptAndOneOfWhatItInheritsIsNot(): void {
        $boat = self::boat();
        $boat->write(self::path('price'), FieldDescriptor::scalar('price', ScalarType::String, Nullability::NotNullable), Conversion::of('5 EUR'));
        $boat->write(self::path('color'), FieldDescriptor::scalar('color', ScalarType::Text, Nullability::NotNullable), Conversion::of('WHITE'));

        self::assertSame('WHITE', $boat->get(self::path('color')), 'Converted all the same: a later fixup sees the new shape.');
        self::assertEquals(['price' => '5 EUR', 'chassis:weight' => 900], $boat->ownValues());
    }

    public function testInAConvertedEmbedEachFieldKeepsWhoSetIt(): void {
        $boat = self::boat();
        $boat->write(self::path('chassis'), FieldDescriptor::embed('chassis', 'ed:chassis'), Conversion::of(null, ['weight' => 950, 'code' => 'D', 'grade' => 'A']));

        self::assertSame(['price' => 5, 'chassis:weight' => 950, 'chassis:grade' => 'A'], $boat->ownValues(), 'A field new to the Embed comes from what the type set there.');
    }

    public function testWhatAnInitializerWritesIsNeverItsOwn(): void {
        $boat = self::boat();
        $boat->write(self::path('serial'), FieldDescriptor::scalar('serial', ScalarType::String, Nullability::NotNullable), Conversion::of('SN-1'));

        self::assertArrayNotHasKey('serial', $boat->ownValues());
    }

    public function testARemovalEndsItAndAReplacementKeepsIt(): void {
        $boat = self::boat();
        $boat->removeAt(self::path('price'));
        $boat->write(self::path('price'), FieldDescriptor::scalar('price', ScalarType::Int, Nullability::NotNullable), Conversion::of(7));
        $boat->replaceAt(self::path('chassis:weight'), null, ValueMark::Converted);

        self::assertSame(['chassis:weight' => null], $boat->ownValues(), 'A field dropped then added again is new to it.');
    }

    public function testAnEntityKeepsNothingAsDefaults(): void {
        $entity = new AggregateValues('ed:boat', ['price' => 5], ['price' => ValueMark::Touched], []);

        self::assertSame([], $entity->ownValues());
    }
}
