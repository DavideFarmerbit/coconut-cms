<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Address;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Product;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\WithNullableField;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\WithUnannotatedProperty;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\WithUnmarkedClassTypedField;

final class PrototypeShapeTest extends TestCase
{
    public function testExtractsScalarFieldsWithQueryableAndUniqueFlags(): void
    {
        $fields = PrototypeShape::ofClass(Product::class);
        $byName = self::byName($fields);

        self::assertSame(FieldKind::String, $byName['sku']->kind);
        self::assertTrue($byName['sku']->queryable);
        self::assertTrue($byName['sku']->unique);

        self::assertFalse($byName['description']->queryable);
        self::assertSame(FieldKind::Bool, $byName['active']->kind);
    }

    public function testUnannotatedConstructorParameterIsSkipped(): void
    {
        $fields = PrototypeShape::ofClass(WithUnannotatedProperty::class);

        self::assertCount(1, $fields);
        self::assertSame('name', $fields[0]->name);
    }

    public function testEmbedAttributeProducesAnEmbeddedValueObjectField(): void
    {
        $fields = PrototypeShape::ofClass(Product::class);
        $address = self::byName($fields)['address'];

        self::assertSame(FieldKind::EmbeddedValueObject, $address->kind);
        self::assertSame(Address::class, $address->referencedShape);
    }

    public function testMissingLabelFallsBackToTheUppercasedPropertyName(): void
    {
        $fields = PrototypeShape::ofClass(Product::class);

        self::assertSame('Sku', self::byName($fields)['sku']->label);
    }

    public function testNullableFieldTypeIsRejected(): void
    {
        $this->expectException(LogicException::class);

        PrototypeShape::ofClass(WithNullableField::class);
    }

    public function testClassTypedFieldWithoutEmbedOrReferenceIsRejected(): void
    {
        $this->expectException(LogicException::class);

        PrototypeShape::ofClass(WithUnmarkedClassTypedField::class);
    }

    /**
     * @param \XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor[] $fields
     * @return array<string, \XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor>
     */
    private static function byName(array $fields): array
    {
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field->name] = $field;
        }

        return $byName;
    }
}
