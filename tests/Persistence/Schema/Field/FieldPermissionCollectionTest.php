<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Field;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;

final class FieldPermissionCollectionTest extends TestCase
{
    public function testGetReturnsThePermissionRegisteredUnderThatName(): void {
        $permission = new FieldPermission(write: 'editor');
        $collection = FieldPermissionCollection::fromArray(['wholesaleCost' => $permission]);

        self::assertSame($permission, $collection->get(FieldName::of('wholesaleCost')));
    }

    public function testGetOnAFieldWithNoRestrictionReturnsNullRatherThanThrowing(): void {
        $collection = FieldPermissionCollection::empty();

        self::assertNull($collection->get(FieldName::of('sku')));
    }

    public function testHasDistinguishesARestrictedFieldFromAnUnrestrictedOne(): void {
        $collection = FieldPermissionCollection::fromArray([
            'wholesaleCost' => new FieldPermission(write: 'editor'),
        ]);

        self::assertTrue($collection->has(FieldName::of('wholesaleCost')));
        self::assertFalse($collection->has(FieldName::of('sku')));
    }

    public function testIterationYieldsFieldNameKeysAndTheirPermissions(): void {
        $permission = new FieldPermission(write: 'editor');
        $collection = FieldPermissionCollection::fromArray(['wholesaleCost' => $permission]);

        $seen = [];
        foreach ($collection as $name => $value) {
            $seen[(string) $name] = $value;
        }

        self::assertSame(['wholesaleCost' => $permission], $seen);
    }

    public function testCountReflectsTheNumberOfRestrictedFields(): void {
        $collection = FieldPermissionCollection::fromArray([
            'a' => new FieldPermission(write: 'editor'),
            'b' => new FieldPermission(read: 'anyone'),
        ]);

        self::assertCount(2, $collection);
    }
}
