<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;

final class FieldDescriptorTest extends TestCase
{
    public function testScalarRejectsANonScalarKind(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FieldDescriptor::scalar('name', FieldKind::EmbeddedValueObject, 'Name');
    }

    public function testUniqueForcesQueryableRegardlessOfWhatWasPassed(): void
    {
        $field = FieldDescriptor::scalar('sku', FieldKind::String, 'Sku', queryable: false, unique: true);

        self::assertTrue($field->queryable);
        self::assertTrue($field->unique);
    }

    public function testChoiceRejectsAnEmptyOptionList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FieldDescriptor::choice('status', [], 'Status');
    }

    public function testEmbedIsNeverQueryableAsAWhole(): void
    {
        $field = FieldDescriptor::embed('address', 'AddressClass', 'Address');

        self::assertFalse($field->queryable);
        self::assertSame(FieldKind::EmbeddedValueObject, $field->kind);
    }
}
