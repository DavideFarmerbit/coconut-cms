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
use XyloIsCoding\CoconutCms\Persistence\Schema\EntityRegistrar;
use XyloIsCoding\CoconutCms\Persistence\Schema\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\ScalarType;
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

    public function checkUsage(array $fields): void {
        $names = array_map(static fn (FieldDescriptor $field): string => $field->name(), $fields);

        foreach ([$this->greaterField, $this->lesserField] as $name) {
            if (!in_array($name, $names, true)) {
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

final class EntityRegistrarTest extends TestCase
{
    public function testRegisterReflectsEntityAttributeAndFields(): void {
        $schema = EntityRegistrar::register(EntityRegistrarProduct::class);

        self::assertSame(EntityRegistrarProduct::class, $schema->identifier);
        self::assertNull($schema->parent);
        self::assertSame('products', $schema->table);
        self::assertTrue($schema->editorExtensible);

        self::assertSame(ScalarType::String, $schema->fields['sku']->scalarType());
        self::assertSame(EntityRegistrarCategory::class, $schema->fields['category']->target());
        self::assertSame(EntityRegistrarEngine::class, $schema->fields['engine']->target());
        self::assertSame(FieldKind::Collection, $schema->fields['relatedCategories']->kind());
        self::assertSame(EntityRegistrarCategory::class, $schema->fields['relatedCategories']->itemDescriptor()->target());
    }

    public function testRegisterDerivesTableNameWhenNoneIsGiven(): void {
        $schema = EntityRegistrar::register(EntityRegistrarBlogPost::class);

        self::assertSame('entity_registrar_blog_post', $schema->table);
    }

    public function testRegisterCollectsFieldPermissionsAndValidators(): void {
        $schema = EntityRegistrar::register(EntityRegistrarProduct::class);

        self::assertArrayNotHasKey('sku', $schema->fieldPermissions);
        self::assertSame('editor', $schema->fieldPermissions['title']->write);

        self::assertCount(1, $schema->fieldValidators['title']);
        self::assertInstanceOf(EntityRegistrarMaxLength::class, $schema->fieldValidators['title'][0]);
    }

    public function testRegisterCollectsUniqueGroupsAndPrototypeValidators(): void {
        $schema = EntityRegistrar::register(EntityRegistrarProduct::class);

        self::assertSame([['sku']], $schema->uniqueGroups);
        self::assertCount(1, $schema->prototypeValidators);
        self::assertInstanceOf(EntityRegistrarGreaterThan::class, $schema->prototypeValidators[0]);
    }

    public function testRegisterWithUniqueOnUnknownFieldThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarUniqueOnUnknownField::class);
    }

    public function testRegisterWithUniqueOnParentFieldThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarUniqueOnParentField::class);
    }

    public function testRegisterResolvesDefaultInstanceFromImplicitConstructor(): void {
        $schema = EntityRegistrar::register(EntityRegistrarProduct::class);

        self::assertInstanceOf(EntityRegistrarProduct::class, $schema->defaultInstance());
    }

    public function testRegisterResolvesDefaultInstanceFromMarkedMethod(): void {
        $schema = EntityRegistrar::register(EntityRegistrarWithDefaultFactory::class);

        self::assertSame(42, $schema->defaultInstance()->value);
    }

    public function testRegisterResolvesParentWhenItIsAnEntity(): void {
        $schema = EntityRegistrar::register(EntityRegistrarDerived::class);

        self::assertSame(EntityRegistrarBase::class, $schema->parent);
        self::assertArrayHasKey('extra', $schema->fields);
        self::assertArrayNotHasKey('createdAt', $schema->fields);
    }

    public function testRegisterInfersEnumClassFromThePropertysOwnType(): void {
        $schema = EntityRegistrar::register(EntityRegistrarWithInferredEnum::class);

        self::assertSame(EntityRegistrarStatus::class, $schema->fields['status']->enumClass());
    }

    public function testRegisterWithUninferableEnumClassThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarWithUninferableEnum::class);
    }

    public function testRegisterWithoutEntityAttributeThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarNotAnEntity::class);
    }

    public function testRegisterWithNonEntityParentTreatsItAsRoot(): void {
        $schema = EntityRegistrar::register(EntityRegistrarChildOfPlainBase::class);

        self::assertNull($schema->parent);
    }

    public function testRegisterWithReferenceToNonEntityThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarWithReferenceToNonEntity::class);
    }

    public function testRegisterWithOwningReferenceToNonEntityThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarWithOwningReferenceToNonEntity::class);
    }

    public function testRegisterWithEmbedOfNonEntityThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarWithEmbedOfNonEntity::class);
    }

    public function testRegisterWithCollectionItemMissingTargetThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarWithCollectionItemMissingTarget::class);
    }

    public function testRegisterWithZeroKindAttributesThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarZeroKindAttributes::class);
    }

    public function testRegisterWithTwoKindAttributesThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarTwoKindAttributes::class);
    }

    public function testRegisterWithNonStaticDefaultInstanceThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarDefaultInstanceNotStatic::class);
    }

    public function testRegisterWithParameterizedDefaultInstanceThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarDefaultInstanceWithParams::class);
    }

    public function testRegisterWithTwoDefaultInstancesThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarTwoDefaultInstances::class);
    }

    public function testRegisterWithNoDefaultInstanceOptionThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarNoDefaultInstance::class);
    }

    public function testRegisterRunsFieldValidatorCheckUsage(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarBadFieldValidator::class);
    }

    public function testRegisterRunsPrototypeValidatorCheckUsage(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarBadPrototypeValidator::class);
    }

    public function testRegisterWithUninitializedFieldOnDefaultInstanceThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarWithUninitializedField::class);
    }

    public function testRegisterWithChildLeavingAnInheritedFieldUninitializedThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        EntityRegistrar::register(EntityRegistrarChildLeavingInheritedFieldUninitialized::class);
    }

    public function testRegisterIgnoresAFieldAttributeOnANonEntityAncestor(): void {
        $schema = EntityRegistrar::register(EntityRegistrarWithNonEntityAncestorFieldAttribute::class);

        self::assertArrayHasKey('own', $schema->fields);
    }
}
