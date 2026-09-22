<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\RowMapper;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Address;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Product;

final class RowMapperTest extends TestCase
{
    /** @return array{0: \XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor[], 1: array<string, mixed>} */
    private static function sampleValues(): array
    {
        $fields = PrototypeShape::ofClass(Product::class);
        $values = [
            'sku' => 'ABC-1',
            'name' => 'Widget',
            'description' => 'A fine widget',
            'address' => new Address('Rome', 'Via Roma 1'),
            'active' => true,
        ];

        return [$fields, $values];
    }

    public function testQueryableFieldsBecomeRealColumns(): void
    {
        [$fields, $values] = self::sampleValues();

        $row = RowMapper::toRow($fields, $values);

        self::assertSame('ABC-1', $row['sku']);
        self::assertSame('Widget', $row['name']);
        self::assertSame(1, $row['active'], 'bool must round-trip through the column as 0/1');
    }

    public function testNonQueryableFieldsGoIntoTheBlobColumnInstead(): void
    {
        [$fields, $values] = self::sampleValues();

        $row = RowMapper::toRow($fields, $values);

        self::assertArrayNotHasKey('description', $row);
        $blob = json_decode($row[SchemaBuilder::BLOB_COLUMN], true);
        self::assertSame('A fine widget', $blob['description']);
    }

    public function testEmbeddedQueryableSubfieldIsDotFlattenedIntoItsOwnColumn(): void
    {
        [$fields, $values] = self::sampleValues();

        $row = RowMapper::toRow($fields, $values);

        self::assertSame('Rome', $row['address_city']);
        self::assertArrayNotHasKey('address', $row);
    }

    public function testEmbeddedNonQueryableSubfieldStaysNestedInTheBlob(): void
    {
        [$fields, $values] = self::sampleValues();

        $row = RowMapper::toRow($fields, $values);
        $blob = json_decode($row[SchemaBuilder::BLOB_COLUMN], true);

        self::assertSame('Via Roma 1', $blob['address']['street']);
    }

    public function testFromRowReconstructsTheOriginalValues(): void
    {
        [$fields, $values] = self::sampleValues();

        $row = RowMapper::toRow($fields, $values);
        $restored = RowMapper::fromRow($fields, $row);

        self::assertSame('ABC-1', $restored['sku']);
        self::assertSame('A fine widget', $restored['description']);
        self::assertTrue($restored['active']);
        self::assertInstanceOf(Address::class, $restored['address']);
        self::assertSame('Rome', $restored['address']->city);
        self::assertSame('Via Roma 1', $restored['address']->street);
    }
}
