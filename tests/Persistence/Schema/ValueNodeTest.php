<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\OwnedNode;

final class ValueNodeTest extends TestCase
{
    public function testAnObjectNodeTellsNotHeldFromHeldAsNothing(): void {
        $node = ObjectNode::fromArray(['engine' => null]);

        self::assertTrue($node->has(FieldName::of('engine')));
        self::assertNull($node->get(FieldName::of('engine')));
        self::assertFalse($node->has(FieldName::of('chassis')));
        self::assertNull($node->find(FieldName::of('chassis')));
    }

    public function testGetOnAFieldNotHeldThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        ObjectNode::empty()->get(FieldName::of('chassis'));
    }

    public function testFromPassesTheRightNodeAndNullThrough(): void {
        $object = ObjectNode::empty();
        $owned = new OwnedNode('App\\Engine', $object);
        $collection = new CollectionNode([]);

        self::assertSame($object, ObjectNode::from($object));
        self::assertSame($owned, OwnedNode::from($owned));
        self::assertSame($collection, CollectionNode::from($collection));
        self::assertNull(OwnedNode::from(null));
        self::assertSame(5, LeafNode::valueOf(new LeafNode(5)));
        self::assertNull(LeafNode::valueOf(null));
    }

    public function testFromRejectsTheWrongKindOfNode(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Expected an OwnedNode/');

        OwnedNode::from(ObjectNode::empty());
    }

    public function testValueOfRejectsANonLeaf(): void {
        $this->expectException(InvalidArgumentException::class);

        LeafNode::valueOf(new CollectionNode([]));
    }

    public function testACollectionNodeReindexesItsItems(): void {
        $node = new CollectionNode([3 => new LeafNode('a'), 7 => null]);

        self::assertSame([0, 1], array_keys($node->items));
        self::assertCount(2, $node);
    }
}
