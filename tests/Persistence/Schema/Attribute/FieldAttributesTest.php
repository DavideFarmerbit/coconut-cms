<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Attribute;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

final class FieldAttributesSubject
{
    #[Scalar(ScalarType::String)]
    private string $title;

    #[Scalar(ScalarType::Enum)]
    private FieldAttributesStatus $status;

    #[ValueObject]
    private string $price;

    #[Embed]
    private object $billingAddress;

    #[Reference]
    private ?object $category;

    #[OwningReference]
    private ?object $engine;

    #[Collection(itemKind: FieldKind::Reference, itemTarget: 'Tag')]
    private array $tags;
}

enum FieldAttributesStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final class FieldAttributesTest extends TestCase
{
    private function attributeOf(string $property): object {
        $reflected = (new ReflectionClass(FieldAttributesSubject::class))->getProperty($property);

        return $reflected->getAttributes()[0]->newInstance();
    }

    public function testScalarAttributeReflectsType(): void {
        $attribute = $this->attributeOf('title');

        self::assertSame(ScalarType::String, $attribute->type);
    }

    /** The enum class is the property's own declared type, never an argument. */
    public function testAnEnumScalarAttributeTakesOnlyItsType(): void {
        $attribute = $this->attributeOf('status');

        self::assertSame(ScalarType::Enum, $attribute->type);
        self::assertSame(['type'], array_keys(get_object_vars($attribute)));
    }

    /** Their target is the property's own declared type, never an argument. */
    public function testTargetInferringAttributesTakeNoArguments(): void {
        self::assertInstanceOf(ValueObject::class, $this->attributeOf('price'));
        self::assertInstanceOf(Embed::class, $this->attributeOf('billingAddress'));
        self::assertInstanceOf(Reference::class, $this->attributeOf('category'));
        self::assertInstanceOf(OwningReference::class, $this->attributeOf('engine'));
    }

    public function testCollectionAttributeReflectsFlattenedItemParams(): void {
        $attribute = $this->attributeOf('tags');

        self::assertSame(FieldKind::Reference, $attribute->itemKind);
        self::assertSame('Tag', $attribute->itemTarget);
        self::assertNull($attribute->itemScalarType);
        self::assertNull($attribute->itemEnumClass);
    }
}
