<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Field;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;

enum ScalarValuesTestFuel: string
{
    case Petrol = 'petrol';
}

enum ScalarValuesTestGear: int
{
    case First = 1;
}

final class ScalarValuesTest extends TestCase
{
    public function testAnEnumIsStoredAsItsBackingValue(): void {
        self::assertSame('petrol', ScalarValues::toStorage(ScalarType::Enum, ScalarValuesTestFuel::Petrol));
        self::assertSame(1, ScalarValues::toStorage(ScalarType::Enum, ScalarValuesTestGear::First));
    }

    public function testADateIsStoredAsItsDayInItsOwnTimezone(): void {
        $lateInRome = new DateTimeImmutable('2024-03-10 23:30:00', new DateTimeZone('Europe/Rome'));

        self::assertSame('2024-03-10', ScalarValues::toStorage(ScalarType::Date, $lateInRome));
    }

    public function testADateTimeIsStoredInUtcToTheSecond(): void {
        $inRome = new DateTimeImmutable('2024-03-10 23:30:15.250', new DateTimeZone('Europe/Rome'));

        self::assertSame('2024-03-10 22:30:15', ScalarValues::toStorage(ScalarType::DateTime, $inRome));
    }

    public function testTheSameInstantInTwoTimezonesStoresTheSame(): void {
        $inRome = new DateTimeImmutable('2024-03-10 23:30:00', new DateTimeZone('Europe/Rome'));
        $inNewYork = $inRome->setTimezone(new DateTimeZone('America/New_York'));

        self::assertSame(ScalarValues::toStorage(ScalarType::DateTime, $inRome), ScalarValues::toStorage(ScalarType::DateTime, $inNewYork));
    }

    public function testNullIsStoredAsNullForAnyType(): void {
        foreach (ScalarType::cases() as $type) {
            self::assertNull(ScalarValues::toStorage($type, null), $type->name);
        }
    }

    public function testEveryOtherTypeIsStoredAsIs(): void {
        self::assertSame('a', ScalarValues::toStorage(ScalarType::String, 'a'));
        self::assertSame(3, ScalarValues::toStorage(ScalarType::Int, 3));
        self::assertSame(1.5, ScalarValues::toStorage(ScalarType::Float, 1.5));
        self::assertTrue(ScalarValues::toStorage(ScalarType::Bool, true));
        self::assertSame('12.50', ScalarValues::toStorage(ScalarType::Decimal, '12.50'));
    }

    public function testAValueOfTheWrongKindThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A DateTime scalar must hold a DateTimeInterface, got string.');

        ScalarValues::toStorage(ScalarType::DateTime, '2024-03-10');
    }
}
