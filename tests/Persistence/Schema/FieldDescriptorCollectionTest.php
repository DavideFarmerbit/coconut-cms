<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

final class FieldDescriptorCollectionTest extends TestCase
{
    public function testGetReturnsTheFieldRegisteredUnderThatName(): void {
        $sku = FieldDescriptor::scalar('sku', ScalarType::String);
        $collection = FieldDescriptorCollection::fromArray(['sku' => $sku]);

        self::assertSame($sku, $collection->get(FieldName::of('sku')));
    }

    public function testGetOnAnUnknownNameThrows(): void {
        $collection = FieldDescriptorCollection::empty();

        $this->expectException(InvalidArgumentException::class);

        $collection->get(FieldName::of('missing'));
    }

    public function testHasDistinguishesKnownFromUnknownNames(): void {
        $collection = FieldDescriptorCollection::fromArray([
            'sku' => FieldDescriptor::scalar('sku', ScalarType::String),
        ]);

        self::assertTrue($collection->has(FieldName::of('sku')));
        self::assertFalse($collection->has(FieldName::of('missing')));
    }

    public function testWithReturnsANewCollectionLeavingTheOriginalUntouched(): void {
        $original = FieldDescriptorCollection::empty();
        $sku = FieldDescriptor::scalar('sku', ScalarType::String);

        $withSku = $original->with(FieldName::of('sku'), $sku);

        self::assertFalse($original->has(FieldName::of('sku')));
        self::assertTrue($withSku->has(FieldName::of('sku')));
    }

    public function testMergeLetsTheOtherCollectionsFieldWinOnASharedName(): void {
        $base = FieldDescriptorCollection::fromArray([
            'name' => FieldDescriptor::scalar('name', ScalarType::String),
        ]);
        $override = FieldDescriptorCollection::fromArray([
            'name' => FieldDescriptor::scalar('name', ScalarType::Text),
        ]);

        $merged = $base->merge($override);

        self::assertSame(ScalarType::Text, $merged->get(FieldName::of('name'))->scalarType());
    }

    public function testMergeKeepsFieldsOnlyPresentInEitherSide(): void {
        $base = FieldDescriptorCollection::fromArray([
            'inherited' => FieldDescriptor::scalar('inherited', ScalarType::String),
        ]);
        $override = FieldDescriptorCollection::fromArray([
            'own' => FieldDescriptor::scalar('own', ScalarType::String),
        ]);

        $merged = $base->merge($override);

        self::assertTrue($merged->has(FieldName::of('inherited')));
        self::assertTrue($merged->has(FieldName::of('own')));
    }

    public function testIterationYieldsFieldNameKeysAndTheirDescriptors(): void {
        $sku = FieldDescriptor::scalar('sku', ScalarType::String);
        $collection = FieldDescriptorCollection::fromArray(['sku' => $sku]);

        $seen = [];
        foreach ($collection as $name => $field) {
            $seen[(string) $name] = $field;
        }

        self::assertSame(['sku' => $sku], $seen);
    }

    public function testCountReflectsTheNumberOfFields(): void {
        $collection = FieldDescriptorCollection::fromArray([
            'a' => FieldDescriptor::scalar('a', ScalarType::String),
            'b' => FieldDescriptor::scalar('b', ScalarType::String),
        ]);

        self::assertCount(2, $collection);
    }
}
