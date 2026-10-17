<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntityRegistrar;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaRegistry;

#[Entity]
class SchemaRegistryRoot
{
    #[Scalar(ScalarType::String)]
    private string $name = '';
}

#[Entity]
final class SchemaRegistryChild extends SchemaRegistryRoot
{
    #[Scalar(ScalarType::String)]
    private string $extra = '';
}

#[Entity]
final class SchemaRegistryOverrideChild extends SchemaRegistryRoot
{
    #[Scalar(ScalarType::Text)]
    private string $name = '';
}

#[Entity]
final class SchemaRegistryEmbedTarget
{
    #[Scalar(ScalarType::String)]
    private string $value = '';
}

#[Entity]
final class SchemaRegistryEmbedder
{
    #[Embed(SchemaRegistryEmbedTarget::class)]
    private object $target;

    public function __construct() {
        $this->target = new SchemaRegistryEmbedTarget();
    }
}

#[Entity]
final class SchemaRegistryDeepEmbedMiddle
{
    #[Embed(SchemaRegistryEmbedTarget::class)]
    private object $target;

    public function __construct() {
        $this->target = new SchemaRegistryEmbedTarget();
    }
}

#[Entity]
final class SchemaRegistryDeepEmbedOuter
{
    #[Embed(SchemaRegistryDeepEmbedMiddle::class)]
    private object $middle;

    public function __construct() {
        $this->middle = new SchemaRegistryDeepEmbedMiddle();
    }
}

#[Entity]
final class SchemaRegistryCollectionEmbedder
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: SchemaRegistryEmbedTarget::class)]
    private array $items = [];
}

#[Entity]
final class SchemaRegistryCycleA
{
    #[Embed(SchemaRegistryCycleB::class)]
    private ?object $b = null;
}

#[Entity]
final class SchemaRegistryCycleB
{
    #[Embed(SchemaRegistryCycleA::class)]
    private ?object $a = null;
}

#[Entity]
final class SchemaRegistryNode
{
    #[OwningReference(SchemaRegistryNode::class)]
    private ?object $child = null;
}

final class SchemaRegistryTest extends TestCase
{
    private static function emptySchema(string $identifier, ?string $parent): EntitySchema {
        return new EntitySchema(
            identifier: $identifier,
            parent: $parent,
            table: 'whatever',
            editorExtensible: false,
            fields: [],
            fieldPermissions: [],
            fieldValidators: [],
            uniqueGroups: [],
            prototypeValidators: [],
            defaultInstanceFactory: static fn (): object => new stdClass(),
        );
    }

    public function testSortOrdersParentBeforeChild(): void {
        $schemas = [
            SchemaRegistryChild::class => EntityRegistrar::register(SchemaRegistryChild::class),
            SchemaRegistryRoot::class => EntityRegistrar::register(SchemaRegistryRoot::class),
        ];

        $order = array_keys(SchemaRegistry::sort($schemas));

        self::assertSame([SchemaRegistryRoot::class, SchemaRegistryChild::class], $order);
    }

    public function testSortOrdersEmbedTargetBeforeEmbedder(): void {
        $schemas = [
            SchemaRegistryEmbedder::class => EntityRegistrar::register(SchemaRegistryEmbedder::class),
            SchemaRegistryEmbedTarget::class => EntityRegistrar::register(SchemaRegistryEmbedTarget::class),
        ];

        $order = array_keys(SchemaRegistry::sort($schemas));

        self::assertSame([SchemaRegistryEmbedTarget::class, SchemaRegistryEmbedder::class], $order);
    }

    public function testSortOrdersATransitiveEmbedChainCorrectly(): void {
        $schemas = [
            SchemaRegistryDeepEmbedOuter::class => EntityRegistrar::register(SchemaRegistryDeepEmbedOuter::class),
            SchemaRegistryDeepEmbedMiddle::class => EntityRegistrar::register(SchemaRegistryDeepEmbedMiddle::class),
            SchemaRegistryEmbedTarget::class => EntityRegistrar::register(SchemaRegistryEmbedTarget::class),
        ];

        $order = array_keys(SchemaRegistry::sort($schemas));

        self::assertSame([
            SchemaRegistryEmbedTarget::class,
            SchemaRegistryDeepEmbedMiddle::class,
            SchemaRegistryDeepEmbedOuter::class,
        ], $order);
    }

    public function testSortOrdersCollectionEmbedItemTargetBeforeEmbedder(): void {
        $schemas = [
            SchemaRegistryCollectionEmbedder::class => EntityRegistrar::register(SchemaRegistryCollectionEmbedder::class),
            SchemaRegistryEmbedTarget::class => EntityRegistrar::register(SchemaRegistryEmbedTarget::class),
        ];

        $order = array_keys(SchemaRegistry::sort($schemas));

        self::assertSame([SchemaRegistryEmbedTarget::class, SchemaRegistryCollectionEmbedder::class], $order);
    }

    public function testSortLeavesASelfReferencingOwningReferenceAlone(): void {
        $schemas = [SchemaRegistryNode::class => EntityRegistrar::register(SchemaRegistryNode::class)];

        $order = array_keys(SchemaRegistry::sort($schemas));

        self::assertSame([SchemaRegistryNode::class], $order);
    }

    public function testSortWithMissingParentThrows(): void {
        $schemas = [SchemaRegistryChild::class => EntityRegistrar::register(SchemaRegistryChild::class)];

        $this->expectException(InvalidArgumentException::class);

        SchemaRegistry::sort($schemas);
    }

    public function testSortWithMissingEmbedTargetThrows(): void {
        $schemas = [SchemaRegistryEmbedder::class => EntityRegistrar::register(SchemaRegistryEmbedder::class)];

        $this->expectException(InvalidArgumentException::class);

        SchemaRegistry::sort($schemas);
    }

    public function testSortWithEmbedCycleThrows(): void {
        $schemas = [
            SchemaRegistryCycleA::class => EntityRegistrar::register(SchemaRegistryCycleA::class),
            SchemaRegistryCycleB::class => EntityRegistrar::register(SchemaRegistryCycleB::class),
        ];

        $this->expectException(InvalidArgumentException::class);

        SchemaRegistry::sort($schemas);
    }

    public function testSortWithParentCycleThrows(): void {
        $schemas = [
            'A' => self::emptySchema('A', 'B'),
            'B' => self::emptySchema('B', 'A'),
        ];

        $this->expectException(InvalidArgumentException::class);

        SchemaRegistry::sort($schemas);
    }

    public function testHierarchyFieldsComposesOwnAndInheritedFields(): void {
        $sorted = SchemaRegistry::sort([
            SchemaRegistryChild::class => EntityRegistrar::register(SchemaRegistryChild::class),
            SchemaRegistryRoot::class => EntityRegistrar::register(SchemaRegistryRoot::class),
        ]);

        $hierarchyFields = SchemaRegistry::hierarchyFields($sorted);

        self::assertSame(['name'], array_keys($hierarchyFields[SchemaRegistryRoot::class]));
        self::assertSame(['name', 'extra'], array_keys($hierarchyFields[SchemaRegistryChild::class]));
    }

    public function testHierarchyFieldsLetsAChildsOwnFieldOverrideAnInheritedOne(): void {
        $sorted = SchemaRegistry::sort([
            SchemaRegistryOverrideChild::class => EntityRegistrar::register(SchemaRegistryOverrideChild::class),
            SchemaRegistryRoot::class => EntityRegistrar::register(SchemaRegistryRoot::class),
        ]);

        $hierarchyFields = SchemaRegistry::hierarchyFields($sorted);

        self::assertSame(ScalarType::Text, $hierarchyFields[SchemaRegistryOverrideChild::class]['name']->scalarType());
    }

    public function testHierarchyFieldsLeavesAnEmbedFieldUnexpanded(): void {
        $sorted = SchemaRegistry::sort([
            SchemaRegistryEmbedder::class => EntityRegistrar::register(SchemaRegistryEmbedder::class),
            SchemaRegistryEmbedTarget::class => EntityRegistrar::register(SchemaRegistryEmbedTarget::class),
        ]);

        $hierarchyFields = SchemaRegistry::hierarchyFields($sorted);

        self::assertSame(FieldKind::Embed, $hierarchyFields[SchemaRegistryEmbedder::class]['target']->kind());
        self::assertSame(SchemaRegistryEmbedTarget::class, $hierarchyFields[SchemaRegistryEmbedder::class]['target']->target());
    }
}
