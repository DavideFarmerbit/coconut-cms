<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\ScalarType;

final class FieldAttributesSubject
{
    #[Scalar(ScalarType::String)]
    private string $title;

    #[Scalar(ScalarType::Enum, FieldAttributesStatus::class)]
    private FieldAttributesStatus $status;

    #[ValueObject('Money')]
    private string $price;

    #[Embed('Address')]
    private object $billingAddress;

    #[Reference('Category')]
    private ?object $category;

    #[OwningReference('Engine')]
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
        self::assertNull($attribute->enumClass);
    }

    public function testScalarAttributeReflectsEnumClass(): void {
        $attribute = $this->attributeOf('status');

        self::assertSame(ScalarType::Enum, $attribute->type);
        self::assertSame(FieldAttributesStatus::class, $attribute->enumClass);
    }

    public function testValueObjectAttributeReflectsType(): void {
        self::assertSame('Money', $this->attributeOf('price')->type);
    }

    public function testEmbedAttributeReflectsType(): void {
        self::assertSame('Address', $this->attributeOf('billingAddress')->type);
    }

    public function testReferenceAttributeReflectsType(): void {
        self::assertSame('Category', $this->attributeOf('category')->type);
    }

    public function testOwningReferenceAttributeReflectsType(): void {
        self::assertSame('Engine', $this->attributeOf('engine')->type);
    }

    public function testCollectionAttributeReflectsFlattenedItemParams(): void {
        $attribute = $this->attributeOf('tags');

        self::assertSame(FieldKind::Reference, $attribute->itemKind);
        self::assertSame('Tag', $attribute->itemTarget);
        self::assertNull($attribute->itemScalarType);
        self::assertNull($attribute->itemEnumClass);
    }
}
