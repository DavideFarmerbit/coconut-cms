<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Native;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\EnumType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\FieldRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\PrototypeRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\ValueObjectType;
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

#[ValueObjectType('shop:money')]
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

#[EnumType('shop:color')]
enum RegistrationsColor: string
{
    case Red = 'red';
}

#[FieldRule('shop:max-length')]
final class RegistrationsMaxLength implements FieldValidatorRule
{
    public function checkUsage(FieldDescriptor $field): void {
    }

    public function validate(mixed $value): ValidationResult {
        return ValidationResult::valid();
    }

    public function toStorage(): array {
        return [];
    }

    public static function fromStorage(array $stored): static {
        return new static();
    }
}

#[PrototypeRule('shop:dates-in-order')]
final class RegistrationsDatesInOrder implements PrototypeValidatorRule
{
    public function checkUsage(FieldDescriptorCollection $fields): void {
    }

    public function validate(EntityView $entity): ValidationResult {
        return ValidationResult::valid();
    }

    public function toStorage(): array {
        return [];
    }

    public static function fromStorage(array $stored): static {
        return new static();
    }
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
        self::assertSame('shop:color', (string) NativeIdentifiers::storedAs(RegistrationsColor::class));
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

    public function testListedRulesJoinTheOnesTheCodeUsesUnderTheirOwnAttribute(): void {
        $classes = NativeRegistrations::classesOf(NativeEntityReader::readAll([RegistrationsCar::class]), [RegistrationsMaxLength::class, RegistrationsDatesInOrder::class, RegistrationsMaxLength::class]);

        self::assertSame([RegistrationsMaxLength::class], $classes['fieldValidators']);
        self::assertSame([RegistrationsDatesInOrder::class], $classes['prototypeValidators']);

        $fieldValidators = new FieldValidatorRegistry();
        $prototypeValidators = new PrototypeValidatorRegistry();
        NativeRegistrations::registerAll($classes, new ValueObjectRegistry(), new EnumRegistry(), $fieldValidators, $prototypeValidators);

        self::assertSame(RegistrationsMaxLength::class, $fieldValidators->resolve(Identifier::of('shop', 'max-length')));
        self::assertSame(RegistrationsDatesInOrder::class, $prototypeValidators->resolve(Identifier::of('shop', 'dates-in-order')));
    }

    public function testAListedClassThatIsNoRuleIsRefused(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(RegistrationsColor::class . ' is listed as a validator');

        NativeRegistrations::classesOf([], [RegistrationsColor::class]);
    }
}
