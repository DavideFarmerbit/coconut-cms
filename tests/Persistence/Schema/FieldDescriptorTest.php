<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

final class FieldDescriptorTest extends TestCase
{
    public function testScalarStoresKindNameAndType(): void {
        $field = FieldDescriptor::scalar('title', ScalarType::String);

        self::assertSame(FieldKind::Scalar, $field->kind());
        self::assertSame('title', $field->name());
        self::assertSame(ScalarType::String, $field->scalarType());
        self::assertNull($field->enumClass());
    }

    public function testScalarEnumStoresEnumClass(): void {
        $field = FieldDescriptor::scalar('status', ScalarType::Enum, FieldDescriptorTestStatus::class);

        self::assertSame(ScalarType::Enum, $field->scalarType());
        self::assertSame(FieldDescriptorTestStatus::class, $field->enumClass());
    }

    public function testScalarEnumWithoutEnumClassThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        FieldDescriptor::scalar('status', ScalarType::Enum);
    }

    public function testScalarNonEnumWithEnumClassThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        FieldDescriptor::scalar('title', ScalarType::String, FieldDescriptorTestStatus::class);
    }

    public function testValueObjectStoresTarget(): void {
        $field = FieldDescriptor::valueObject('price', 'Money');

        self::assertSame(FieldKind::ValueObject, $field->kind());
        self::assertSame('Money', $field->target());
    }

    public function testEmbedStoresTarget(): void {
        $field = FieldDescriptor::embed('billingAddress', 'Address');

        self::assertSame(FieldKind::Embed, $field->kind());
        self::assertSame('Address', $field->target());
    }

    public function testReferenceStoresTarget(): void {
        $field = FieldDescriptor::reference('category', 'Category');

        self::assertSame(FieldKind::Reference, $field->kind());
        self::assertSame('Category', $field->target());
    }

    public function testOwningReferenceStoresTarget(): void {
        $field = FieldDescriptor::owningReference('engine', 'Engine');

        self::assertSame(FieldKind::OwningReference, $field->kind());
        self::assertSame('Engine', $field->target());
    }

    public function testCollectionStoresItemDescriptor(): void {
        $item = FieldDescriptor::reference('item', 'Tag');
        $field = FieldDescriptor::collection('tags', $item);

        self::assertSame(FieldKind::Collection, $field->kind());
        self::assertSame($item, $field->itemDescriptor());
    }

    public function testCollectionOfCollectionThrows(): void {
        $item = FieldDescriptor::collection('inner', FieldDescriptor::reference('item', 'Tag'));

        $this->expectException(InvalidArgumentException::class);

        FieldDescriptor::collection('outer', $item);
    }

    public function testScalarTypeOnWrongKindThrows(): void {
        $field = FieldDescriptor::reference('category', 'Category');

        $this->expectException(LogicException::class);

        $field->scalarType();
    }

    public function testTargetOnWrongKindThrows(): void {
        $field = FieldDescriptor::scalar('title', ScalarType::String);

        $this->expectException(LogicException::class);

        $field->target();
    }

    public function testItemDescriptorOnWrongKindThrows(): void {
        $field = FieldDescriptor::scalar('title', ScalarType::String);

        $this->expectException(LogicException::class);

        $field->itemDescriptor();
    }
}

enum FieldDescriptorTestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
