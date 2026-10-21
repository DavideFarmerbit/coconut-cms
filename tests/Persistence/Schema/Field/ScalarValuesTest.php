<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Field;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAnIntWidensToAFloatTheWayPhpDoes(): void {
        self::assertSame(3.0, ScalarValues::toStorage(ScalarType::Float, 3));
    }

    public function testEveryOtherTypeIsStoredAsIs(): void {
        self::assertSame('a', ScalarValues::toStorage(ScalarType::String, 'a'));
        self::assertSame(3, ScalarValues::toStorage(ScalarType::Int, 3));
        self::assertSame(1.5, ScalarValues::toStorage(ScalarType::Float, 1.5));
        self::assertTrue(ScalarValues::toStorage(ScalarType::Bool, true));
        self::assertSame('12.50', ScalarValues::toStorage(ScalarType::Decimal, '12.50'));
    }

    /** @return array<string, array{ScalarType, mixed}> */
    public static function valuesOfTheWrongKind(): array {
        return [
            'an int as a String' => [ScalarType::String, 3],
            'a numeric string as an Int' => [ScalarType::Int, '3'],
            'a numeric string as a Float' => [ScalarType::Float, '1.5'],
            'an int as a Bool' => [ScalarType::Bool, 1],
            'a float as a Decimal' => [ScalarType::Decimal, 1.5],
            'a string as a Date' => [ScalarType::Date, '2024-03-10'],
            'a mutable DateTime as a DateTime' => [ScalarType::DateTime, new DateTime('2024-03-10')],
            'a string as an Enum' => [ScalarType::Enum, 'petrol'],
        ];
    }

    #[DataProvider('valuesOfTheWrongKind')]
    public function testAValueOfTheWrongKindThrows(ScalarType $type, mixed $value): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('A %s scalar must hold', $type->name));

        ScalarValues::toStorage($type, $value);
    }
}
