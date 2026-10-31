<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\Builtin;

use BackedEnum;
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
}
