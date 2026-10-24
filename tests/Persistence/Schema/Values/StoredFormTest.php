<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Values;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\StoredForm;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

enum StoredFormFuel: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
}

/** Stores as "<amount> <currency>"; anything else can't be rebuilt. */
final readonly class StoredFormMoney implements StorableValue
{
    public function __construct(public string $stored) {
    }

    public function toStorage(): string {
        return $this->stored;
    }

    public static function indexable(): bool {
        return true;
    }

    public static function fromStorage(string $stored): static {
        if (preg_match('/^\d+ [A-Z]{3}$/', $stored) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is no amount of money.', $stored));
        }

        return new self($stored);
    }
}

final class StoredFormTest extends TestCase
{
    /** @param array<string, FieldDescriptor> $fields */
    private static function schema(string $identifier, array $fields, ?string $parent = null): EntitySchema {
        return new EntitySchema(
            identifier: $identifier,
            parent: $parent,
            table: str_replace(':', '_', $identifier),
            editorExtensible: false,
            fields: FieldDescriptorCollection::fromArray($fields),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
        );
    }

    /** A car with a field of every kind, an engine and a turbo engine extending it, a chassis, a maker. */
    private static function storedForm(): StoredForm {
        $schemas = [];
        foreach ([
            self::schema('test:chassis', ['weight' => FieldDescriptor::scalar('weight', ScalarType::Int, Nullability::NotNullable)]),
            self::schema('test:engine', ['horsepower' => FieldDescriptor::scalar('horsepower', ScalarType::Int, Nullability::NotNullable)]),
            self::schema('test:turbo_engine', ['boost' => FieldDescriptor::scalar('boost', ScalarType::Float, Nullability::Nullable)], parent: 'test:engine'),
            self::schema('test:maker', []),
            self::schema('test:car', [
                'name' => FieldDescriptor::scalar('name', ScalarType::String, Nullability::NotNullable),
                'nickname' => FieldDescriptor::scalar('nickname', ScalarType::String, Nullability::Nullable),
                'notes' => FieldDescriptor::scalar('notes', ScalarType::Text, Nullability::Nullable),
                'price' => FieldDescriptor::scalar('price', ScalarType::Decimal, Nullability::Nullable),
                'speed' => FieldDescriptor::scalar('speed', ScalarType::Float, Nullability::Nullable),
                'electric' => FieldDescriptor::scalar('electric', ScalarType::Bool, Nullability::Nullable),
                'built' => FieldDescriptor::scalar('built', ScalarType::Date, Nullability::Nullable),
                'sold' => FieldDescriptor::scalar('sold', ScalarType::DateTime, Nullability::Nullable),
                'fuel' => FieldDescriptor::scalar('fuel', ScalarType::Enum, Nullability::Nullable, StoredFormFuel::class, ScalarType::String),
                'cost' => FieldDescriptor::valueObject('cost', StoredFormMoney::class, true, Nullability::Nullable),
                'maker' => FieldDescriptor::reference('maker', 'test:maker'),
                'chassis' => FieldDescriptor::embed('chassis', 'test:chassis'),
                'engine' => FieldDescriptor::owningReference('engine', 'test:engine', Nullability::NotNullable),
                'sizes' => FieldDescriptor::collection('sizes', FieldDescriptor::scalar('sizes', ScalarType::Int, Nullability::NotNullable)),
                'spares' => FieldDescriptor::collection('spares', FieldDescriptor::owningReference('spares', 'test:engine', Nullability::Nullable)),
                'wheels' => FieldDescriptor::collection('wheels', FieldDescriptor::embed('wheels', 'test:chassis')),
            ]),
        ] as $schema) {
            $schemas[$schema->identifier] = $schema;
        }

        return new StoredForm(new SchemaLookup((new InMemorySchemaStores())->registry, $schemas));
    }

    /** @return array<string, mixed> A valid car, every field set. */
    private static function car(): array {
        return [
            'name' => 'Family car',
            'nickname' => null,
            'notes' => str_repeat('long ', 100),
            'price' => '12.5',
            'speed' => 180,
            'electric' => false,
            'built' => '2024-02-29',
            'sold' => '2025-01-01 10:30:00',
            'fuel' => 'diesel',
            'cost' => '100 EUR',
            'maker' => '0193a2b4-0000-7000-8000-000000000000',
            'chassis:weight' => 1200,
            'engine' => 'test:turbo_engine',
            'engine.horsepower' => 450,
            'engine.boost' => 1.5,
            'sizes' => 2,
            'sizes.0' => 17,
            'sizes.1' => 18,
            'spares' => 2,
            'spares.0' => null,
            'spares.1' => 'test:engine',
            'spares.1.horsepower' => 200,
            'wheels' => 1,
            'wheels.0.weight' => 20,
        ];
    }

    public function testAValidAggregatePassesInCanonicalForm(): void {
        $checked = self::storedForm()->checked('test:car', self::car());

        self::assertSame('12.500000', $checked['price']);
        self::assertSame(180.0, $checked['speed']);
        self::assertSame(array_diff_key(self::car(), ['price' => 0, 'speed' => 0]), array_diff_key($checked, ['price' => 0, 'speed' => 0]));
    }

    public function testAValueLeftOutIsNoProblem(): void {
        self::assertSame(['name' => 'Bare'], self::storedForm()->checked('test:car', ['name' => 'Bare']));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidValues(): array {
        return [
            'null where not nullable' => [['name' => null], '"name" can\'t be null.'],
            'an int for a string' => [['name' => 3], '"name" must hold a string of 255 characters at most, got 3.'],
            'a string too long' => [['name' => str_repeat('a', 256)], '"name" must hold a string of 255 characters at most'],
            'a malformed decimal' => [['price' => 'twelve'], '"price": A Decimal scalar must hold a number like "12.50", got "twelve".'],
            'a float for an int' => [['chassis:weight' => 1.5], '"chassis:weight" must hold an int, got 1.5.'],
            'a string for a bool' => [['electric' => 'no'], '"electric" must hold a bool'],
            'an impossible date' => [['built' => '2023-02-29'], '"built" must hold a date written "Y-m-d"'],
            'a date for a date time' => [['sold' => '2025-01-01'], '"sold" must hold a UTC date and time'],
            'an enum case that doesn\'t exist' => [['fuel' => 'steam'], '"fuel" must hold the backing value of a case of'],
            'a value object it can\'t rebuild' => [['cost' => 'a lot'], '"cost": "a lot" is no amount of money.'],
            'a reference that isn\'t an id' => [['maker' => 7], '"maker" must hold an entity id, got 7.'],
            'an owned type outside the target' => [['engine' => 'test:chassis'], '"engine" must hold the identifier of "test:engine" or of a type extending it'],
            'an empty slot that can\'t be' => [['engine' => null], '"engine" can\'t be null.'],
            'a null item that can\'t be' => [['sizes' => 1, 'sizes.0' => null], '"sizes.0" can\'t be null.'],
            'a count that isn\'t one' => [['sizes' => 'two'], '"sizes" must hold an item count, got \'two\'.'],
            'an owned field' => [['engine' => 'test:engine', 'engine.horsepower' => 'many'], '"engine.horsepower" must hold an int'],
            'an Embed item field' => [['wheels' => 1, 'wheels.0.weight' => null], '"wheels.0.weight" can\'t be null.'],
        ];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidValues')]
    public function testAnInvalidValueIsRejectedNamingItsPath(array $values, string $message): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::storedForm()->checked('test:car', $values);
    }

    public function testUnknownPathsAreWhatNoFieldHas(): void {
        $values = [
            'name' => 'Family car',
            'gone' => 1,
            'chassis' => 'an Embed has no value',
            'sizes' => 1,
            'sizes.0' => 17,
            'sizes.1' => 18,
            'engine' => 'test:engine',
            'engine.boost' => 2.0,
        ];

        self::assertSame(['gone', 'chassis', 'sizes.1', 'engine.boost'], self::storedForm()->unknownPaths('test:car', $values));
    }
}
