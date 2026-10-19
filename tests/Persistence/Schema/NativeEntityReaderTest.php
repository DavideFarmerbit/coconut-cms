<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
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
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
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

    #[Reference(EntityRegistrarCategory::class)]
    private ?object $category = null;

    #[OwningReference(EntityRegistrarEngine::class)]
    private ?object $engine = null;

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
    private string $createdAt = '';
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
    #[Reference(EntityRegistrarNotAnEntity::class)]
    private ?object $target;
}

#[Entity]
final class EntityRegistrarWithOwningReferenceToNonEntity
{
    #[OwningReference(EntityRegistrarNotAnEntity::class)]
    private ?object $target;
}

#[Entity]
final class EntityRegistrarWithEmbedOfNonEntity
{
    #[Embed(EntityRegistrarNotAnEntity::class)]
    private object $target;
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
final class EntityRegistrarZeroKindAttributes
{
    private string $broken;
}

#[Entity]
final class EntityRegistrarTwoKindAttributes
{
    #[Scalar(ScalarType::String)]
    #[Reference(EntityRegistrarCategory::class)]
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
    #[OwningReference(EntityRegistrarEngineBase::class)]
    private ?object $engine;

    public function __construct() {
        $this->engine = new EntityRegistrarSportsEngine();
    }
}

#[Entity]
final class EntityRegistrarCarWithEmbed
{
    #[Embed(EntityRegistrarEngineBase::class)]
    private object $engine;

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

final class NativeEntityReaderTest extends TestCase
{
    public function testRegisterReflectsEntityAttributeAndFields(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarProduct::class);

        self::assertSame(EntityRegistrarProduct::class, $blueprint->identifier);
        self::assertNull($blueprint->parent);
        self::assertSame('products', $blueprint->table);
        self::assertTrue($blueprint->editorExtensible);

        self::assertSame(ScalarType::String, $blueprint->fields->get(FieldName::of('sku'))->scalarType());
        self::assertSame(EntityRegistrarCategory::class, $blueprint->fields->get(FieldName::of('category'))->target());
        self::assertSame(EntityRegistrarEngine::class, $blueprint->fields->get(FieldName::of('engine'))->target());
        self::assertSame(FieldKind::Collection, $blueprint->fields->get(FieldName::of('relatedCategories'))->kind());
        self::assertSame(EntityRegistrarCategory::class, $blueprint->fields->get(FieldName::of('relatedCategories'))->itemDescriptor()->target());
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

        self::assertSame([['sku']], $blueprint->uniqueGroups);
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

        self::assertSame(42, $blueprint->defaultValues['value']);
    }

    public function testRegisterResolvesParentWhenItIsAnEntity(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarDerived::class);

        self::assertSame(EntityRegistrarBase::class, $blueprint->parent);
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

        self::assertSame(EntityRegistrarGrandparent::class, $blueprint->parent);
        self::assertSame(['own'], array_map('strval', $blueprint->fields->names()));
        self::assertSame(['createdAt', 'own'], array_keys($blueprint->defaultValues));
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

    public function testRegisterWithZeroKindAttributesThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarZeroKindAttributes::class);
    }

    public function testRegisterWithTwoKindAttributesThrows(): void {
        $this->expectException(InvalidArgumentException::class);

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

    public function testRegisterRunsFieldValidatorCheckUsage(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarBadFieldValidator::class);
    }

    public function testRegisterRunsPrototypeValidatorCheckUsage(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::read(EntityRegistrarBadPrototypeValidator::class);
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

        self::assertSame('', $blueprint->defaultValues['sku']);
        self::assertSame(0, $blueprint->defaultValues['startDate']);
        self::assertNull($blueprint->defaultValues['category']);
        self::assertNull($blueprint->defaultValues['engine']);
        self::assertSame([], $blueprint->defaultValues['relatedCategories']);
    }

    public function testRegisterExtractsOwningReferenceValuePolymorphically(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarCarWithOwningReference::class);

        self::assertSame(EntityRegistrarSportsEngine::class, $blueprint->defaultValues['engine']['class']);
        self::assertSame(
            ['horsepower' => 100, 'redline' => 7000],
            $blueprint->defaultValues['engine']['fields'],
        );
    }

    public function testRegisterExtractsEmbedValueWithTypeErasure(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarCarWithEmbed::class);

        self::assertSame(['horsepower' => 100], $blueprint->defaultValues['engine']);
    }

    public function testRegisterExtractsCollectionItemsRecursively(): void {
        $blueprint = NativeEntityReader::read(EntityRegistrarGarage::class);

        self::assertSame(
            [['class' => EntityRegistrarSportsEngine::class, 'fields' => ['horsepower' => 100, 'redline' => 7000]]],
            $blueprint->defaultValues['engines'],
        );
    }
}
