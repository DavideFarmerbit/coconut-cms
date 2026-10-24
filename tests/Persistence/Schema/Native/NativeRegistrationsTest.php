<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Native;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\StoredAs;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeRegistrations;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;

#[StoredAs('shop:money')]
final readonly class RegistrationsMoney implements StorableValue
{
    public function __construct(public string $stored = '0 EUR') {
    }

    public function toStorage(): string {
        return $this->stored;
    }

    public static function indexable(): bool {
        return true;
    }

    public static function fromStorage(string $stored): static {
        return new self($stored);
    }
}

enum RegistrationsFuelType: string
{
    case Petrol = 'petrol';
}

#[Entity]
final class RegistrationsCar
{
    #[ValueObject]
    public RegistrationsMoney $price;

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::Enum, itemEnumClass: RegistrationsFuelType::class, itemNullability: Nullability::NotNullable)]
    public array $fuels = [];

    #[Scalar(ScalarType::Enum)]
    public RegistrationsFuelType $fuel = RegistrationsFuelType::Petrol;

    public function __construct() {
        $this->price = new RegistrationsMoney();
    }
}

final class NativeRegistrationsTest extends TestCase
{
    public function testAClassIsStoredAsItsAttributeSaysOrByItsShortName(): void {
        self::assertSame('shop:money', (string) NativeIdentifiers::storedAs(RegistrationsMoney::class));
        self::assertSame('default:registrations_fuel_type', (string) NativeIdentifiers::storedAs(RegistrationsFuelType::class));
    }

    public function testEveryClassTheFieldsUseIsFoundOnceAndRegistered(): void {
        $classes = NativeRegistrations::classesOf(NativeEntityReader::readAll([RegistrationsCar::class]));

        self::assertSame([RegistrationsMoney::class], $classes['valueObjects']);
        self::assertSame([RegistrationsFuelType::class], $classes['enums']);

        $valueObjects = new ValueObjectRegistry();
        $enums = new EnumRegistry();
        NativeRegistrations::registerAll($classes, $valueObjects, $enums, new FieldValidatorRegistry(), new PrototypeValidatorRegistry());

        self::assertSame(RegistrationsMoney::class, $valueObjects->resolve(Identifier::of('shop', 'money')));
        self::assertSame(RegistrationsFuelType::class, $enums->resolve(Identifier::of('default', 'registrations_fuel_type')));
    }
}
