<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntityRegistrar;
use XyloIsCoding\CoconutCms\Persistence\Schema\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\NativeSchemaColumnFlattener;
use XyloIsCoding\CoconutCms\Persistence\Schema\ScalarType;

#[Entity]
final class FlattenerPiston
{
    #[Scalar(ScalarType::Int)]
    private int $diameter = 42;
}

#[Entity]
final class FlattenerEngineWithPiston
{
    #[Embed(FlattenerPiston::class)]
    private object $piston;

    public function __construct() {
        $this->piston = new FlattenerPiston();
    }
}

#[Entity]
final class FlattenerChassis
{
    #[Embed(FlattenerEngineWithPiston::class)]
    private object $engine;

    public function __construct() {
        $this->engine = new FlattenerEngineWithPiston();
    }
}

#[Entity]
final class FlattenerCarThreeDeepEmbed
{
    #[Embed(FlattenerChassis::class)]
    private object $chassis;

    public function __construct() {
        $this->chassis = new FlattenerChassis();
    }
}

#[Entity]
final class FlattenerProductWithTags
{
    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String)]
    private array $tags = [];
}

#[Entity]
final class FlattenerBattery
{
    #[Scalar(ScalarType::Int)]
    private int $capacity = 75;
}

#[Entity]
final class FlattenerChassisWithBattery
{
    #[OwningReference(FlattenerBattery::class)]
    private ?object $battery;

    public function __construct() {
        $this->battery = new FlattenerBattery();
    }
}

#[Entity]
final class FlattenerCarEmbedContainingOwned
{
    #[Embed(FlattenerChassisWithBattery::class)]
    private object $chassis;

    public function __construct() {
        $this->chassis = new FlattenerChassisWithBattery();
    }
}

#[Entity]
final class FlattenerGarageOfEmbeddedPistons
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: FlattenerPiston::class)]
    private array $pistons = [];
}

final class NativeSchemaColumnFlattenerTest extends TestCase
{
    public function testFlattenMapsAPlainScalarFieldDirectlyToAColumn(): void {
        $schema = EntityRegistrar::register(EntityRegistrarEngineBase::class);

        $result = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues);

        self::assertSame(['horsepower' => 100], $result['columns']);
        self::assertSame([], $result['external']);
    }

    public function testFlattenExcludesAnOwningReferenceFromColumnsAndRecordsItsConcreteClass(): void {
        $schema = EntityRegistrar::register(EntityRegistrarCarWithOwningReference::class);

        $result = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['engine' => EntityRegistrarSportsEngine::class], $result['external']);
    }

    public function testFlattenAppliesTypeErasureWhenFlatteningAnEmbedsFields(): void {
        $schema = EntityRegistrar::register(EntityRegistrarCarWithEmbed::class);

        $result = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues);

        // EntityRegistrarSportsEngine's own "redline" field never shows up: the Embed
        // is declared against EntityRegistrarEngineBase, so only its own fields flatten.
        self::assertSame(['engine_horsepower' => 100], $result['columns']);
        self::assertSame([], $result['external']);
    }

    public function testFlattenRecursesThroughEmbedOfEmbedOfEmbed(): void {
        $schema = EntityRegistrar::register(FlattenerCarThreeDeepEmbed::class);

        $result = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues);

        self::assertSame(['chassis_engine_piston_diameter' => 42], $result['columns']);
        self::assertSame([], $result['external']);
    }

    public function testFlattenExcludesACollectionOfScalarsAndNamesItsItemKind(): void {
        $schema = EntityRegistrar::register(FlattenerProductWithTags::class);

        $result = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['tags' => 'Scalar'], $result['external']);
    }

    public function testFlattenExcludesACollectionOfOwningReferencesAndNamesItsItemTarget(): void {
        $schema = EntityRegistrar::register(EntityRegistrarGarage::class);

        $result = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['engines' => EntityRegistrarEngineBase::class], $result['external']);
    }

    public function testFlattenExcludesACollectionOfEmbedsAndNamesItsItemTarget(): void {
        $schema = EntityRegistrar::register(FlattenerGarageOfEmbeddedPistons::class);

        $result = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['pistons' => FlattenerPiston::class], $result['external']);
    }

    public function testFlattenFindsAnOwningReferenceNestedInsideAnEmbedAtItsPrefixedPath(): void {
        $schema = EntityRegistrar::register(FlattenerCarEmbedContainingOwned::class);

        $result = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['chassis_battery' => FlattenerBattery::class], $result['external']);
    }
}
