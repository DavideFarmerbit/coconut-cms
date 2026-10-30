<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\Builtin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConstantValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConversionFailed;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\CopyOfField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\DateToDateTime;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\OnFailure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToEnum;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToNumber;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValuesSnapshot;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/** A string-backed enum for TextToEnum. */
enum BuiltinColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}

/** An int-backed enum for TextToEnum. */
enum BuiltinSize: int
{
    case Small = 1;
    case Large = 2;
}

/** The built-in initializers and converters, one value at a time. */
final class BuiltinConversionsTest extends TestCase
{
    /** What $converter makes of $value, touched; "default" when the entry follows the new default. */
    private static function converted(FieldConverter $converter, mixed $value): mixed {
        return self::outcome($converter->convert(new Captured($value, false, new ValuesSnapshot([], []))));
    }

    private static function outcome(Conversion $conversion): mixed {
        return $conversion->isDefault() ? 'default' : $conversion->value();
    }

    public function testConstantValueGivesEveryEntryTheSameValue(): void {
        self::assertSame(12, self::outcome((new ConstantValue(12))->initialize(new ValuesSnapshot(['name' => 'Ark'], ['name' => false]))));
    }

    public function testCopyOfFieldGivesEachEntryItsOwnValue(): void {
        $values = new ValuesSnapshot(['name' => 'Ark', 'engine.power' => 90], ['name' => false, 'engine.power' => true]);

        self::assertSame('Ark', self::outcome((new CopyOfField('name'))->initialize($values)));
        self::assertSame(90, self::outcome((new CopyOfField('engine.power'))->initialize($values)));
        self::assertSame('default', self::outcome((new CopyOfField('engine.serial'))->initialize($values)));
    }

    public function testAValueFollowingTheOldDefaultFollowsTheNewOne(): void {
        foreach ([new NumberToText(), new TextToNumber(ScalarType::Int, OnFailure::Cancel), new TextToEnum(BuiltinColor::class, OnFailure::Cancel), new DateToDateTime()] as $converter) {
            self::assertSame('default', self::outcome($converter->convert(new Captured('anything', true, new ValuesSnapshot([], [])))), $converter::class);
            self::assertNull(self::converted($converter, null), $converter::class);
        }
    }

    public function testNumberToText(): void {
        self::assertSame('150', self::converted(new NumberToText(), 150));
        self::assertSame('2.5', self::converted(new NumberToText(), 2.5));
        self::assertSame('0.00001', self::converted(new NumberToText(), 0.00001));
        self::assertSame('12.5', self::converted(new NumberToText(), '12.500000'));
        self::assertSame('12', self::converted(new NumberToText(), '12.000000'));

        $this->expectException(InvalidArgumentException::class);
        self::converted(new NumberToText(), true);
    }

    public function testTextToNumber(): void {
        self::assertSame(150, self::converted(new TextToNumber(ScalarType::Int, OnFailure::Cancel), ' 150 '));
        self::assertSame(-3, self::converted(new TextToNumber(ScalarType::Int, OnFailure::Cancel), '-3'));
        self::assertSame(2.5, self::converted(new TextToNumber(ScalarType::Float, OnFailure::Cancel), '2.5'));
        self::assertSame('12.5', self::converted(new TextToNumber(ScalarType::Decimal, OnFailure::Cancel), '12.5'));
        self::assertSame('default', self::converted(new TextToNumber(ScalarType::Int, OnFailure::Default), '1.5'));
        self::assertSame('default', self::converted(new TextToNumber(ScalarType::Int, OnFailure::Default), '99999999999999999999'));
        self::assertSame('default', self::converted(new TextToNumber(ScalarType::Decimal, OnFailure::Default), '1e5'));

        $this->expectException(ConversionFailed::class);
        $this->expectExceptionMessage('TextToNumber can\'t convert "call us".');
        self::converted(new TextToNumber(ScalarType::Float, OnFailure::Cancel), 'call us');
    }

    public function testTextToNumberConvertsToNumbersOnly(): void {
        $this->expectException(InvalidArgumentException::class);
        new TextToNumber(ScalarType::Bool, OnFailure::Cancel);
    }

    public function testTextToEnum(): void {
        self::assertSame('red', self::converted(new TextToEnum(BuiltinColor::class, OnFailure::Cancel), 'red'));
        self::assertSame(2, self::converted(new TextToEnum(BuiltinSize::class, OnFailure::Cancel), '2'));
        self::assertSame('default', self::converted(new TextToEnum(BuiltinColor::class, OnFailure::Default), 'green'));

        $this->expectException(ConversionFailed::class);
        self::converted(new TextToEnum(BuiltinSize::class, OnFailure::Cancel), 'huge');
    }

    public function testDateToDateTime(): void {
        self::assertSame('2026-10-30 00:00:00', self::converted(new DateToDateTime(), '2026-10-30'));

        $this->expectException(InvalidArgumentException::class);
        self::converted(new DateToDateTime(), '30/10/2026');
    }
}
