<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Native;

use DateTimeImmutable;
use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\PrototypeValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Method\DefaultInstance;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

enum EntityRegistrarStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

#[Entity]
final class EntityRegistrarWithInferredEnum
{
    #[Scalar(ScalarType::Enum)]
    private EntityRegistrarStatus $status = EntityRegistrarStatus::Draft;
}

#[Entity]
final class EntityRegistrarWithUninferableEnum
{
    #[Scalar(ScalarType::Enum)]
    private mixed $status;
}

final class EntityRegistrarMaxLength implements FieldValidatorRule
{
    public function __construct(private readonly int $max) {
    }

    public function checkUsage(FieldDescriptor $field): void {
        if ($field->scalarType() !== ScalarType::String && $field->scalarType() !== ScalarType::Text) {
            throw new InvalidArgumentException('MaxLength only applies to a String or Text scalar field.');
        }
    }

    public function toStorage(): array {
        return ['max' => $this->max];
    }

    public static function fromStorage(array $stored): static {
        return new static($stored['max']);
    }

    public function validate(mixed $value): ValidationResult {
        return strlen((string) $value) > $this->max
            ? ValidationResult::invalid(sprintf('must be at most %d characters', $this->max))
            : ValidationResult::valid();
    }
}

final class EntityRegistrarGreaterThan implements PrototypeValidatorRule
{
    public function __construct(
        private readonly string $greaterField,
        private readonly string $lesserField,
    ) {
    }

    public function checkUsage(FieldDescriptorCollection $fields): void {
        foreach ([$this->greaterField, $this->lesserField] as $name) {
            if (!$fields->has(FieldName::of($name))) {
                throw new InvalidArgumentException(sprintf('Unknown field "%s".', $name));
            }
        }
    }

    public function toStorage(): array {
        return ['greaterField' => $this->greaterField, 'lesserField' => $this->lesserField];
    }

    public static function fromStorage(array $stored): static {
        return new static($stored['greaterField'], $stored['lesserField']);
    }

    public function validate(object $entity): ValidationResult {
        return ValidationResult::valid();
    }
}

#[Entity]
final class EntityRegistrarCategory
{
    #[Scalar(ScalarType::String)]
    private string $name;
}

#[Entity]
final class EntityRegistrarEngine
{
    #[Scalar(ScalarType::Int)]
    private int $horsepower;
}

#[Entity]
final class EntityRegistrarBlogPost
{
    #[Scalar(ScalarType::String)]
    private string $title = '';
}

#[Entity(table: 'products', editorExtensible: true)]
#[Unique('sku')]
#[PrototypeValidator(new EntityRegistrarGreaterThan('endDate', 'startDate'))]
final class EntityRegistrarProduct
{
    #[Scalar(ScalarType::String)]
    private string $sku = '';

    #[FieldPermission(write: 'editor')]
    #[FieldValidator(new EntityRegistrarMaxLength(50))]
    #[Scalar(ScalarType::String)]
    private string $title = '';

    #[Reference]
    private ?EntityRegistrarCategory $category = null;

    #[OwningReference]
    private ?EntityRegistrarEngine $engine = null;

    #[Collection(itemKind: FieldKind::Reference, itemTarget: EntityRegistrarCategory::class)]
    private array $relatedCategories = [];

    #[Scalar(ScalarType::Int)]
    private int $startDate = 0;

    #[Scalar(ScalarType::Int)]
    private int $endDate = 0;
}

#[Entity]
class EntityRegistrarBase
{
    #[Scalar(ScalarType::DateTime)]
    private ?DateTimeImmutable $createdAt = null;
}

#[Entity]
final class EntityRegistrarDerived extends EntityRegistrarBase
{
    #[Scalar(ScalarType::String)]
    private string $extra = '';
}

#[Entity]
#[Unique('doesNotExist')]
final class EntityRegistrarUniqueOnUnknownField
{
    #[Scalar(ScalarType::String)]
    private string $sku;
}

#[Entity]
#[Unique('createdAt')]
final class EntityRegistrarUniqueOnParentField extends EntityRegistrarBase
{
    #[Scalar(ScalarType::String)]
    private string $extra;
}

final class EntityRegistrarNotAnEntity
{
}

#[Entity]
final class EntityRegistrarWithReferenceToNonEntity
{
    #[Reference]
    private ?EntityRegistrarNotAnEntity $target;
}

#[Entity]
final class EntityRegistrarWithOwningReferenceToNonEntity
{
    #[OwningReference]
    private ?EntityRegistrarNotAnEntity $target;
}

#[Entity]
final class EntityRegistrarWithEmbedOfNonEntity
{
    #[Embed]
    private EntityRegistrarNotAnEntity $target;
}

#[Entity]
final class EntityRegistrarWithCollectionItemMissingTarget
{
    #[Collection(itemKind: FieldKind::Reference)]
    private array $items;
}

class EntityRegistrarPlainBase
{
}

#[Entity]
final class EntityRegistrarChildOfPlainBase extends EntityRegistrarPlainBase
{
}

#[Entity]
class EntityRegistrarGrandparent
{
    #[Scalar(ScalarType::String)]
    private string $createdAt = '';
}

class EntityRegistrarNonEntityMiddle extends EntityRegistrarGrandparent
{
}

#[Entity]
final class EntityRegistrarChildPastNonEntityMiddle extends EntityRegistrarNonEntityMiddle
{
    #[Scalar(ScalarType::String)]
    private string $own = '';
}

#[Entity]
final class EntityRegistrarTwoKindAttributes
{
    #[Scalar(ScalarType::String)]
    #[Reference]
    private string $broken;
}

#[Entity]
final class EntityRegistrarDefaultInstanceNotStatic
{
    #[DefaultInstance]
    private function make(): self {
        return new self();
    }
}

#[Entity]
final class EntityRegistrarDefaultInstanceWithParams
{
    #[DefaultInstance]
    private static function make(int $seed): self {
        return new self();
    }
}

#[Entity]
final class EntityRegistrarTwoDefaultInstances
{
    #[DefaultInstance]
    private static function makeOne(): self {
        return new self();
    }

    #[DefaultInstance]
    private static function makeTwo(): self {
        return new self();
    }
}

#[Entity]
final class EntityRegistrarNoDefaultInstance
{
    public function __construct(public int $required) {
    }
}

#[Entity]
final class EntityRegistrarWithDefaultFactory
{
    private function __construct(
        #[Scalar(ScalarType::Int)]
        public readonly int $value,
    ) {
    }

    #[DefaultInstance]
    private static function make(): self {
        return new self(42);
    }
}

#[Entity]
final class EntityRegistrarBadFieldValidator
{
    #[FieldValidator(new EntityRegistrarMaxLength(50))]
    #[Scalar(ScalarType::Int)]
    private int $quantity;
}

#[Entity]
#[PrototypeValidator(new EntityRegistrarGreaterThan('endDate', 'doesNotExist'))]
final class EntityRegistrarBadPrototypeValidator
{
    #[Scalar(ScalarType::Int)]
    private int $endDate;
}

#[Entity]
final class EntityRegistrarFieldPermissionOnNonField
{
    #[FieldPermission(write: 'editor')]
    private string $notAField = '';
}

#[Entity]
final class EntityRegistrarFieldValidatorOnNonField
{
    #[FieldValidator(new EntityRegistrarMaxLength(50))]
    private string $notAField = '';
}

#[Entity]
final class EntityRegistrarWithUninitializedField
{
    #[Scalar(ScalarType::String)]
    private string $name;
}

#[Entity]
class EntityRegistrarBaseWithDefault
{
    #[Scalar(ScalarType::String)]
    protected string $inherited = '';
}

#[Entity]
final class EntityRegistrarChildLeavingInheritedFieldUninitialized extends EntityRegistrarBaseWithDefault
{
    #[Scalar(ScalarType::String)]
    private string $own = '';

    public function __construct() {
        unset($this->inherited);
    }
}

class EntityRegistrarPlainBaseWithFieldAttribute
{
    #[Scalar(ScalarType::String)]
    private string $notAField;
}

#[Entity]
final class EntityRegistrarWithNonEntityAncestorFieldAttribute extends EntityRegistrarPlainBaseWithFieldAttribute
{
    #[Scalar(ScalarType::String)]
    private string $own = '';
}

#[Entity]
class EntityRegistrarBaseWithUninitializedPrivateField
{
    #[Scalar(ScalarType::String)]
    private string $basePrivate;
}

#[Entity]
final class EntityRegistrarChildOfBaseWithUninitializedPrivateField extends EntityRegistrarBaseWithUninitializedPrivateField
{
    #[Scalar(ScalarType::String)]
    private string $own = '';
}

#[Entity]
class EntityRegistrarEngineBase
{
    #[Scalar(ScalarType::Int)]
    private int $horsepower = 100;
}

#[Entity]
final class EntityRegistrarSportsEngine extends EntityRegistrarEngineBase
{
    #[Scalar(ScalarType::Int)]
    private int $redline = 7000;
}

#[Entity]
final class EntityRegistrarCarWithOwningReference
{
    #[OwningReference]
    private ?EntityRegistrarEngineBase $engine;

    public function __construct() {
        $this->engine = new EntityRegistrarSportsEngine();
    }
}

#[Entity]
final class EntityRegistrarCarWithEmbed
{
    #[Embed]
    private EntityRegistrarEngineBase $engine;

    public function __construct() {
        $this->engine = new EntityRegistrarSportsEngine();
    }
}

#[Entity]
final class EntityRegistrarGarage
{
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: EntityRegistrarEngineBase::class)]
    private array $engines;

    public function __construct() {
        $this->engines = [new EntityRegistrarSportsEngine()];
    }
}

final class EntityRegistrarMoney implements StorableValue
{
    public function __construct(
        private readonly string $amount,
    ) {
    }

    public function toStorage(): string {
        return $this->amount;
    }

    public static function indexable(): bool {
        return true;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

#[Entity]
final class EntityRegistrarWithPlainProperty
{
    #[Scalar(ScalarType::String)]
    private string $title = '';

    private array $cache;
}

#[Entity]
final class EntityRegistrarDefaultReference
{
    #[Reference]
    private ?EntityRegistrarCategory $category;

    public function __construct() {
        $this->category = new EntityRegistrarCategory();
    }
}

enum EntityRegistrarPureEnum
{
    case Only;
}

#[Entity]
final class EntityRegistrarDateAsString
{
    #[Scalar(ScalarType::DateTime)]
    private ?string $createdAt = null;
}

#[Entity]
final class EntityRegistrarIntAsString
{
    #[Scalar(ScalarType::Int)]
    private string $count = '0';
}

#[Entity]
final class EntityRegistrarMutableDate
{
    #[Scalar(ScalarType::Date)]
    private ?\DateTime $day = null;
}

#[Entity]
final class EntityRegistrarUnionScalar
{
    #[Scalar(ScalarType::Int)]
    private int|string $count = 0;
}

#[Entity]
final class EntityRegistrarEnumOnString
{
    #[Scalar(ScalarType::Enum)]
    private ?string $status = null;
}

#[Entity]
final class EntityRegistrarPureEnumField
{
    #[Scalar(ScalarType::Enum)]
    private ?EntityRegistrarPureEnum $status = null;
}

#[Entity]
final class EntityRegistrarPureEnumItems
{
    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::Enum, itemEnumClass: EntityRegistrarPureEnum::class)]
    private array $statuses = [];
}

#[Entity]
final class EntityRegistrarWellTypedScalars
{
    #[Scalar(ScalarType::Decimal)]
    private ?string $price = null;

    #[Scalar(ScalarType::Float)]
    private float $ratio = 0.5;

    #[Scalar(ScalarType::Bool)]
    private bool $active = false;

    #[Scalar(ScalarType::Date)]
    private ?DateTimeImmutable $day = null;
}

#[Entity]
final class EntityRegistrarOverLongPrice
{
    #[ValueObject]
    private EntityRegistrarMoney $price;

    public function __construct() {
        $this->price = new EntityRegistrarMoney(str_repeat('9', 256));
    }
}

#[Entity]
final class EntityRegistrarOverLongHistory
{
    #[Collection(itemKind: FieldKind::ValueObject, itemTarget: EntityRegistrarMoney::class)]
    private array $history;

    public function __construct() {
        $this->history = [new EntityRegistrarMoney(str_repeat('9', 256))];
    }
}

#[Entity]
final class EntityRegistrarPriced
{
    #[ValueObject]
    private EntityRegistrarMoney $price;

    #[ValueObject]
    private ?EntityRegistrarMoney $discount = null;

    #[Collection(itemKind: FieldKind::ValueObject, itemTarget: EntityRegistrarMoney::class)]
    private array $history;

    public function __construct() {
        $this->price = new EntityRegistrarMoney('10 EUR');
        $this->history = [new EntityRegistrarMoney('8 EUR'), new EntityRegistrarMoney('9 EUR')];
    }
}

final class EntityRegistrarNotStorable
{
}

#[Entity]
final class EntityRegistrarValueObjectNotStorable
{
    #[ValueObject]
    private ?EntityRegistrarNotStorable $value = null;
}

#[Entity]
final class EntityRegistrarCollectionOfEmbedNonEntity
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: EntityRegistrarNotAnEntity::class)]
    private array $items = [];
}

#[Entity]
final class EntityRegistrarCollectionOfReferenceNonEntity
{
    #[Collection(itemKind: FieldKind::Reference, itemTarget: EntityRegistrarNotAnEntity::class)]
    private array $items = [];
}

#[Entity]
final class EntityRegistrarCollectionOfOwningReferenceNonEntity
{
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: EntityRegistrarNotAnEntity::class)]
    private array $items = [];
}

#[Entity]
final class EntityRegistrarCollectionOfValueObjectNotStorable
{
    #[Collection(itemKind: FieldKind::ValueObject, itemTarget: EntityRegistrarNotStorable::class)]
    private array $items = [];
}

#[Entity]
final class EntityRegistrarEmbedTypedAsObject
{
    #[Embed]
    private ?object $target = null;
}

#[Entity]
final class EntityRegistrarReferenceTypedAsUnion
{
    #[Reference]
    private EntityRegistrarCategory|EntityRegistrarEngine|null $target = null;
}

final class NativeEntityReaderTest extends TestCase
{
    /** The plain value a blueprint's default instance holds at $field. */
    private static function leafAt(EntityBlueprint $blueprint, string $field): mixed {
        return LeafNode::valueOf($blueprint->defaultValues->get(FieldName::of($field)));
    }

    public function testRegisterReflectsEntityAttributeAndFields(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarProduct::class);

        self::assertSame('default:entity_registrar_product', $blueprint->identifier);
        self::assertNull($blueprint->parent);
        self::assertSame('products', $blueprint->table);
        self::assertTrue($blueprint->editorExtensible);

        self::assertSame(ScalarType::String, $blueprint->fields->get(FieldName::of('sku'))->scalarType());
        self::assertSame('default:entity_registrar_category', $blueprint->fields->get(FieldName::of('category'))->target());
        self::assertSame('default:entity_registrar_engine', $blueprint->fields->get(FieldName::of('engine'))->target());
        self::assertSame(FieldKind::Collection, $blueprint->fields->get(FieldName::of('relatedCategories'))->kind());
        self::assertSame('default:entity_registrar_category', $blueprint->fields->get(FieldName::of('relatedCategories'))->itemDescriptor()->target());
    }

    public function testRegisterDerivesTableNameWhenNoneIsGiven(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarBlogPost::class);

        self::assertSame('entity_registrar_blog_post', $blueprint->table);
    }

    public function testRegisterCollectsFieldPermissionsAndValidators(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarProduct::class);

        self::assertFalse($blueprint->fieldPermissions->has(FieldName::of('sku')));
        self::assertSame('editor', $blueprint->fieldPermissions->get(FieldName::of('title'))->write);

        self::assertCount(1, $blueprint->fieldValidators->get(FieldName::of('title')));
        self::assertInstanceOf(EntityRegistrarMaxLength::class, $blueprint->fieldValidators->get(FieldName::of('title'))[0]);
    }

    public function testRegisterCollectsUniqueGroupsAndPrototypeValidators(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarProduct::class);

        self::assertEquals([UniqueGroup::of(FieldName::of('sku'))], $blueprint->uniqueGroups);
        self::assertCount(1, $blueprint->prototypeValidators);
        self::assertInstanceOf(EntityRegistrarGreaterThan::class, $blueprint->prototypeValidators[0]);
    }

    public function testRegisterWithUniqueOnUnknownFieldThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarUniqueOnUnknownField::class);
    }

    public function testRegisterWithUniqueOnParentFieldThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarUniqueOnParentField::class);
    }

    public function testRegisterResolvesDefaultValuesFromMarkedMethod(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarWithDefaultFactory::class);

        self::assertSame(42, self::leafAt($blueprint, 'value'));
    }

    public function testRegisterResolvesParentWhenItIsAnEntity(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarDerived::class);

        self::assertSame('default:entity_registrar_base', $blueprint->parent);
        self::assertTrue($blueprint->fields->has(FieldName::of('extra')));
        self::assertFalse($blueprint->fields->has(FieldName::of('createdAt')));
    }

    public function testRegisterInfersEnumClassFromThePropertysOwnType(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarWithInferredEnum::class);

        self::assertSame(EntityRegistrarStatus::class, $blueprint->fields->get(FieldName::of('status'))->enumClass());
    }

    public function testRegisterWithUninferableEnumClassThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarWithUninferableEnum::class);
    }

    public function testRegisterWithoutEntityAttributeThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarNotAnEntity::class);
    }

    public function testRegisterWithNonEntityParentTreatsItAsRoot(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarChildOfPlainBase::class);

        self::assertNull($blueprint->parent);
    }

    public function testRegisterSkipsPastANonEntityAncestorToFindARealEntityFurtherUp(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarChildPastNonEntityMiddle::class);

        self::assertSame('default:entity_registrar_grandparent', $blueprint->parent);
        self::assertSame(['own'], array_map('strval', $blueprint->fields->names()));
        self::assertSame(['createdAt', 'own'], array_map('strval', $blueprint->defaultValues->names()));
    }

    public function testRegisterWithReferenceToNonEntityThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarWithReferenceToNonEntity::class);
    }

    public function testRegisterWithOwningReferenceToNonEntityThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarWithOwningReferenceToNonEntity::class);
    }

    public function testRegisterWithEmbedOfNonEntityThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarWithEmbedOfNonEntity::class);
    }

    public function testRegisterWithCollectionItemMissingTargetThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarWithCollectionItemMissingTarget::class);
    }

    public function testRegisterWithTwoKindAttributesThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->expectExceptionMessage('carries 2 field-kind attributes');

        NativeEntityReader::read(EntityRegistrarTwoKindAttributes::class);
    }

    public function testRegisterWithNonStaticDefaultInstanceThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarDefaultInstanceNotStatic::class);
    }

    public function testRegisterWithParameterizedDefaultInstanceThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarDefaultInstanceWithParams::class);
    }

    public function testRegisterWithTwoDefaultInstancesThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarTwoDefaultInstances::class);
    }

    public function testRegisterWithNoDefaultInstanceOptionThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarNoDefaultInstance::class);
    }

    public function testRegisterInfersAValueObjectsClassFromItsDeclaredType(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarPriced::class);

        self::assertSame(EntityRegistrarMoney::class, $blueprint->fields->get(FieldName::of('price'))->target());
        self::assertSame(EntityRegistrarMoney::class, $blueprint->fields->get(FieldName::of('history'))->itemDescriptor()->target());
    }

    /** @return array<string, array{class-string}> */
    public static function overLongIndexableDefaults(): array {
        return [
            'a field' => [EntityRegistrarOverLongPrice::class],
            'a collection item' => [EntityRegistrarOverLongHistory::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('overLongIndexableDefaults')]
    public function testRegisterWithAnIndexableDefaultPastTheLimitThrows(string $class): void {
        $this->expectException(LengthException::class);

        NativeEntityReader::read($class);
    }

    public function testRegisterIgnoresAPropertyWithNoFieldKindAttribute(): void {
        // $cache isn't even initialized on the default instance: not a field, so never checked.
        $blueprint = NativeEntityReader::read(EntityRegistrarWithPlainProperty::class);

        self::assertSame(['title'], array_map('strval', $blueprint->fields->names()));
        self::assertFalse($blueprint->defaultValues->has(FieldName::of('cache')));
    }

    public function testRegisterWithADefaultReferenceHoldingAnObjectThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a default Reference must be null for now');

        NativeEntityReader::read(EntityRegistrarDefaultReference::class);
    }

    /** @return array<string, array{class-string, string}> */
    public static function mistypedScalars(): array {
        return [
            'a DateTime as a string' => [EntityRegistrarDateAsString::class, 'must be declared as DateTimeImmutable'],
            'an Int as a string' => [EntityRegistrarIntAsString::class, 'must be declared as int'],
            'a Date as a mutable DateTime' => [EntityRegistrarMutableDate::class, 'must be declared as DateTimeImmutable'],
            'a union type' => [EntityRegistrarUnionScalar::class, 'must be declared as int'],
            'an Enum on a non-enum type' => [EntityRegistrarEnumOnString::class, 'must be declared as a single backed enum class'],
            'a pure enum' => [EntityRegistrarPureEnumField::class, 'not a backed enum'],
            'a pure enum as collection items' => [EntityRegistrarPureEnumItems::class, 'not a backed enum'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('mistypedScalars')]
    public function testRegisterWithAScalarPropertyOfTheWrongTypeThrows(string $class, string $message): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        NativeEntityReader::read($class);
    }

    public function testRegisterAcceptsScalarsDeclaredAsWhatTheirTypeHolds(): void {
        self::assertCount(4, NativeEntityReader::read(EntityRegistrarWellTypedScalars::class)->fields);
    }

    public function testRegisterRecordsWhetherAValueObjectIsIndexable(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarPriced::class);

        self::assertTrue($blueprint->fields->get(FieldName::of('price'))->indexable());
        self::assertTrue($blueprint->fields->get(FieldName::of('history'))->itemDescriptor()->indexable());
    }

    public function testRegisterReadsAValueObjectsDefaultAsTheStringItStoresAs(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarPriced::class);

        self::assertSame('10 EUR', self::leafAt($blueprint, 'price'));
        self::assertNull(self::leafAt($blueprint, 'discount'));
        self::assertEquals(
            new CollectionNode([new LeafNode('8 EUR'), new LeafNode('9 EUR')]),
            $blueprint->defaultValues->get(FieldName::of('history')),
        );
    }

    public function testRegisterWithAValueObjectNotImplementingStorableValueThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        NativeEntityReader::read(EntityRegistrarValueObjectNotStorable::class);
    }

    /** @return array<string, array{class-string}> */
    public static function collectionsOfNonEntities(): array {
        return [
            'Embed items' => [EntityRegistrarCollectionOfEmbedNonEntity::class],
            'Reference items' => [EntityRegistrarCollectionOfReferenceNonEntity::class],
            'OwningReference items' => [EntityRegistrarCollectionOfOwningReferenceNonEntity::class],
        ];
    }

    #[DataProvider('collectionsOfNonEntities')]
    public function testRegisterWithACollectionItemTargetingANonEntityThrows(string $class): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a class marked #\[Entity\]/');

        NativeEntityReader::read($class);
    }

    public function testRegisterWithACollectionOfValueObjectsNotImplementingStorableValueThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        NativeEntityReader::read(EntityRegistrarCollectionOfValueObjectNotStorable::class);
    }

    public function testRegisterWithAnInferredTargetTypedAsObjectThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be declared as a single class/');

        NativeEntityReader::read(EntityRegistrarEmbedTypedAsObject::class);
    }

    public function testRegisterWithAnInferredTargetTypedAsAUnionThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be declared as a single class/');

        NativeEntityReader::read(EntityRegistrarReferenceTypedAsUnion::class);
    }

    public function testRegisterRunsFieldValidatorCheckUsage(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarBadFieldValidator::class);
    }

    public function testRegisterRunsPrototypeValidatorCheckUsage(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarBadPrototypeValidator::class);
    }

    public function testRegisterWithFieldPermissionOnNonFieldPropertyThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarFieldPermissionOnNonField::class);
    }

    public function testRegisterWithFieldValidatorOnNonFieldPropertyThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarFieldValidatorOnNonField::class);
    }

    public function testRegisterWithUninitializedFieldOnDefaultInstanceThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarWithUninitializedField::class);
    }

    public function testRegisterWithChildLeavingAnInheritedFieldUninitializedThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarChildLeavingInheritedFieldUninitialized::class);
    }

    public function testRegisterIgnoresAFieldAttributeOnANonEntityAncestor(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarWithNonEntityAncestorFieldAttribute::class);

        self::assertTrue($blueprint->fields->has(FieldName::of('own')));
    }

    public function testRegisterWithUninitializedPrivateAncestorFieldThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarChildOfBaseWithUninitializedPrivateField::class);
    }

    public function testRegisterExtractsScalarDefaultValues(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarProduct::class);

        self::assertSame('', self::leafAt($blueprint, 'sku'));
        self::assertSame(0, self::leafAt($blueprint, 'startDate'));
        self::assertNull(self::leafAt($blueprint, 'category'));
        // An OwningReference holding nothing is no node at all, not a LeafNode.
        self::assertNull($blueprint->defaultValues->get(FieldName::of('engine')));
        self::assertEquals(new CollectionNode([]), $blueprint->defaultValues->get(FieldName::of('relatedCategories')));
    }

    public function testRegisterExtractsOwningReferenceValuePolymorphically(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarCarWithOwningReference::class);

        self::assertEquals(
            new OwnedNode('default:entity_registrar_sports_engine', ObjectNode::fromArray(['horsepower' => new LeafNode(100), 'redline' => new LeafNode(7000)])),
            $blueprint->defaultValues->get(FieldName::of('engine')),
        );
    }

    public function testRegisterExtractsEmbedValueWithTypeErasure(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarCarWithEmbed::class);

        self::assertEquals(ObjectNode::fromArray(['horsepower' => new LeafNode(100)]), $blueprint->defaultValues->get(FieldName::of('engine')));
    }

    public function testRegisterExtractsCollectionItemsRecursively(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarGarage::class);

        self::assertEquals(
            new CollectionNode([new OwnedNode('default:entity_registrar_sports_engine', ObjectNode::fromArray(['horsepower' => new LeafNode(100), 'redline' => new LeafNode(7000)]))]),
            $blueprint->defaultValues->get(FieldName::of('engines')),
        );
    }
}
