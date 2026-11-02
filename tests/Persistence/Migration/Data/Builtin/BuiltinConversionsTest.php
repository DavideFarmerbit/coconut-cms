<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\Builtin;

use BackedEnum;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConstantValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConversionFailed;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\CopyOfField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\DateTimeToDate;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\DateToDateTime;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\EnumToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\FirstItem;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\MapValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToNumber;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\OnFailure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\OnTooLong;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\Rounding;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ToSingleItem;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ValueMapping;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToEnum;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToNumber;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValuesSnapshot;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
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
    /** What $converter makes of $value, touched, into $to; "default" when the entry follows the new default. */
    private static function converted(FieldConverter $converter, mixed $value, FieldDescriptor $to): mixed {
        return self::outcome($converter->convert(new Captured($value, false, new ValuesSnapshot([], [])), $to));
    }

    /** A field holding a $type Scalar. */
    private static function field(ScalarType $type): FieldDescriptor {
        return FieldDescriptor::scalar('field', $type, Nullability::Nullable);
    }

    /** @param class-string<BackedEnum> $enum A field holding an $enum. */
    private static function enumField(string $enum, ScalarType $backing): FieldDescriptor {
        return FieldDescriptor::scalar('field', ScalarType::Enum, Nullability::Nullable, $enum, $backing);
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
        $cases = [
            [new NumberToText(), self::field(ScalarType::String)],
            [new TextToNumber(OnFailure::Cancel), self::field(ScalarType::Int)],
            [new TextToEnum(OnFailure::Cancel), self::enumField(BuiltinColor::class, ScalarType::String)],
            [new DateToDateTime(), self::field(ScalarType::DateTime)],
        ];
        foreach ($cases as [$converter, $to]) {
            self::assertSame('default', self::outcome($converter->convert(new Captured('anything', true, new ValuesSnapshot([], [])), $to)), $converter::class);
            self::assertNull(self::converted($converter, null, $to), $converter::class);
        }
    }

    public function testNumberToText(): void {
        $text = self::field(ScalarType::String);
        self::assertSame('150', self::converted(new NumberToText(), 150, $text));
        self::assertSame('2.5', self::converted(new NumberToText(), 2.5, $text));
        self::assertSame('0.00001', self::converted(new NumberToText(), 0.00001, $text));
        self::assertSame('12.5', self::converted(new NumberToText(), '12.500000', $text));
        self::assertSame('12', self::converted(new NumberToText(), '12.000000', $text));

        $this->expectException(InvalidArgumentException::class);
        self::converted(new NumberToText(), true, $text);
    }

    public function testTextToNumberGivesTheNumberTheNewFieldHolds(): void {
        $cancel = new TextToNumber(OnFailure::Cancel);
        $default = new TextToNumber(OnFailure::Default);
        self::assertSame(150, self::converted($cancel, ' 150 ', self::field(ScalarType::Int)));
        self::assertSame(-3, self::converted($cancel, '-3', self::field(ScalarType::Int)));
        self::assertSame(2.5, self::converted($cancel, '2.5', self::field(ScalarType::Float)));
        self::assertSame('12.5', self::converted($cancel, '12.5', self::field(ScalarType::Decimal)));
        self::assertSame('default', self::converted($default, '1.5', self::field(ScalarType::Int)));
        self::assertSame('default', self::converted($default, '99999999999999999999', self::field(ScalarType::Int)));
        self::assertSame('default', self::converted($default, '1e5', self::field(ScalarType::Decimal)));

        $this->expectException(ConversionFailed::class);
        $this->expectExceptionMessage('TextToNumber can\'t convert "call us".');
        self::converted($cancel, 'call us', self::field(ScalarType::Float));
    }

    public function testTextToEnumGivesTheEnumTheNewFieldHolds(): void {
        self::assertSame('red', self::converted(new TextToEnum(OnFailure::Cancel), 'red', self::enumField(BuiltinColor::class, ScalarType::String)));
        self::assertSame(2, self::converted(new TextToEnum(OnFailure::Cancel), '2', self::enumField(BuiltinSize::class, ScalarType::Int)));
        self::assertSame('default', self::converted(new TextToEnum(OnFailure::Default), 'green', self::enumField(BuiltinColor::class, ScalarType::String)));

        $this->expectException(ConversionFailed::class);
        self::converted(new TextToEnum(OnFailure::Cancel), 'huge', self::enumField(BuiltinSize::class, ScalarType::Int));
    }

    public function testDateToDateTime(): void {
        self::assertSame('2026-10-30 00:00:00', self::converted(new DateToDateTime(), '2026-10-30', self::field(ScalarType::DateTime)));

        $this->expectException(InvalidArgumentException::class);
        self::converted(new DateToDateTime(), '30/10/2026', self::field(ScalarType::DateTime));
    }

    public function testNumberToNumberRoundsAsItsSaid(): void {
        $int = self::field(ScalarType::Int);
        $halfUp = new NumberToNumber(OnFailure::Cancel);

        self::assertSame(3, self::converted($halfUp, 2.5, $int));
        self::assertSame(-3, self::converted($halfUp, -2.5, $int));
        self::assertSame(2, self::converted(new NumberToNumber(OnFailure::Cancel, Rounding::HalfEven), 2.5, $int));
        self::assertSame(2, self::converted(new NumberToNumber(OnFailure::Cancel, Rounding::Down), 2.9, $int));
        self::assertSame(3, self::converted(new NumberToNumber(OnFailure::Cancel, Rounding::Up), 2.1, $int));
        self::assertSame(2.0, self::converted($halfUp, 2, self::field(ScalarType::Float)));
        self::assertSame('2', self::converted($halfUp, 2, self::field(ScalarType::Decimal)));
        self::assertSame('0.333333', self::converted($halfUp, 1 / 3, self::field(ScalarType::Decimal)));
        self::assertNull(self::converted($halfUp, null, $int));
    }

    public function testNumberToNumberRoundsADecimalExactly(): void {
        $int = self::field(ScalarType::Int);
        $cases = [
            [Rounding::HalfUp, '2.500000', 3], [Rounding::HalfUp, '-2.500000', -3], [Rounding::HalfUp, '2.499999', 2],
            [Rounding::HalfEven, '2.500000', 2], [Rounding::HalfEven, '3.500000', 4], [Rounding::HalfEven, '2.500001', 3],
            [Rounding::Down, '-2.900000', -2], [Rounding::Up, '9.000001', 10], [Rounding::Up, '99.100000', 100],
            [Rounding::HalfUp, '9223372036854775807.000000', PHP_INT_MAX], [Rounding::HalfUp, '-9223372036854775808.000000', PHP_INT_MIN],
        ];
        foreach ($cases as [$rounding, $decimal, $expected]) {
            self::assertSame($expected, self::converted(new NumberToNumber(OnFailure::Cancel, $rounding), $decimal, $int), "$decimal, {$rounding->value}");
        }

        self::assertSame(2.5, self::converted(new NumberToNumber(OnFailure::Cancel), '2.500000', self::field(ScalarType::Float)));
    }

    public function testANumberTheNewTypeCantHoldDoesWhatItsTold(): void {
        $int = self::field(ScalarType::Int);
        $decimal = self::field(ScalarType::Decimal);

        self::assertSame('default', self::converted(new NumberToNumber(OnFailure::Default), '9223372036854775807.500000', $int));
        self::assertSame('default', self::converted(new NumberToNumber(OnFailure::Default), 1e19, $int));
        self::assertSame('default', self::converted(new NumberToNumber(OnFailure::Default), NAN, $decimal));
        self::assertSame('default', self::converted(new NumberToNumber(OnFailure::Default), 123456789012345, $decimal), 'A Decimal holds 14 whole digits.');
        self::assertSame('12345678901234', self::converted(new NumberToNumber(OnFailure::Default), 12345678901234, $decimal));

        $this->expectException(ConversionFailed::class);
        self::converted(new NumberToNumber(OnFailure::Cancel), INF, $int);
    }

    public function testTextToTextCutsOrRefusesWhatAStringCantHold(): void {
        $long = str_repeat('à', 256);
        $string = self::field(ScalarType::String);

        self::assertSame($long, self::converted(new TextToText(OnTooLong::Cancel), $long, self::field(ScalarType::Text)));
        self::assertSame('short', self::converted(new TextToText(OnTooLong::Cancel), 'short', $string));
        self::assertSame(str_repeat('à', 255), self::converted(new TextToText(OnTooLong::Truncate), $long, $string), 'Characters, not bytes.');
        self::assertSame('default', self::converted(new TextToText(OnTooLong::Default), $long, $string));

        $this->expectException(ConversionFailed::class);
        self::converted(new TextToText(OnTooLong::Cancel), $long, $string);
    }

    public function testEnumToTextGivesTheStoredValue(): void {
        self::assertSame('red', self::converted(new EnumToText(), 'red', self::field(ScalarType::String)));
        self::assertSame('2', self::converted(new EnumToText(), 2, self::field(ScalarType::Text)));
        self::assertNull(self::converted(new EnumToText(), null, self::field(ScalarType::String)));
    }

    public function testMapValuesMapsByTypeAndValue(): void {
        $mapping = ValueMapping::of([['yes', true], ['no', false], [null, false], [1, true]]);
        $bool = self::field(ScalarType::Bool);

        self::assertTrue(self::converted(new MapValues($mapping, OnFailure::Cancel), 'yes', $bool));
        self::assertFalse(self::converted(new MapValues($mapping, OnFailure::Cancel), null, $bool), 'Null maps like any value.');
        self::assertTrue(self::converted(new MapValues($mapping, OnFailure::Cancel), 1, $bool));
        self::assertSame('default', self::converted(new MapValues($mapping, OnFailure::Default), '1', $bool), '"1" is no key: 1 is.');
        self::assertNull(self::converted(new MapValues(ValueMapping::of([]), OnFailure::Cancel), null, $bool), 'Null left out stays null.');

        $this->expectException(ConversionFailed::class);
        self::converted(new MapValues($mapping, OnFailure::Cancel), 'maybe', $bool);
    }

    public function testAMappingGivesEachKeyOnce(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"no" is mapped twice');

        ValueMapping::of([['no', false], ['no', true]]);
    }

    public function testDateTimeToDateDropsTheTime(): void {
        self::assertSame('2026-11-02', self::converted(new DateTimeToDate(), '2026-11-02 23:59:00', self::field(ScalarType::Date)));

        $this->expectException(InvalidArgumentException::class);
        self::converted(new DateTimeToDate(), '2026-11-02', self::field(ScalarType::Date));
    }

    public function testAValueBecomesTheOnlyItemAndBack(): void {
        $tags = FieldDescriptor::collection('field', self::field(ScalarType::String));

        $single = (new ToSingleItem())->convert(new Captured('sea', false, new ValuesSnapshot([], [])), $tags);
        self::assertSame([1, ['0' => 'sea']], [$single->value(), $single->under()]);
        $none = (new ToSingleItem())->convert(new Captured(null, false, new ValuesSnapshot([], [])), $tags);
        self::assertSame([0, []], [$none->value(), $none->under()]);

        $items = new Captured(2, false, new ValuesSnapshot(['0' => 'sea', '1' => 'night'], ['0' => false, '1' => false]));
        self::assertSame('sea', self::outcome((new FirstItem())->convert($items, self::field(ScalarType::String))));
        self::assertSame('default', self::outcome((new FirstItem())->convert(new Captured(0, false, new ValuesSnapshot([], [])), self::field(ScalarType::String))), 'An empty list follows the new default.');
    }
}
