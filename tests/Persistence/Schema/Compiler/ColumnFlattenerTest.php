<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Compiler;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\ColumnFlattener;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;

#[Entity]
class FlattenerEngineBase
{
    #[Scalar(ScalarType::Int)]
    private int $horsepower = 100;
}

#[Entity]
final class FlattenerSportsEngine extends FlattenerEngineBase
{
    #[Scalar(ScalarType::Int)]
    private int $redline = 7000;
}

#[Entity]
final class FlattenerCarWithOwningReference
{
    #[OwningReference(FlattenerEngineBase::class)]
    private ?object $engine;

    public function __construct() {
        $this->engine = new FlattenerSportsEngine();
    }
}

#[Entity]
final class FlattenerCarWithEmbed
{
    #[Embed(FlattenerEngineBase::class)]
    private object $engine;

    public function __construct() {
        $this->engine = new FlattenerSportsEngine();
    }
}

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
final class FlattenerGarage
{
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: FlattenerEngineBase::class)]
    private array $engines = [];
}

#[Entity]
final class FlattenerGarageOfEmbeddedPistons
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: FlattenerPiston::class)]
    private array $pistons = [];
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

final class ColumnFlattenerTest extends TestCase
{
    private ColumnFlattener $flattener;

    protected function setUp(): void {
        // This test exercises ColumnFlattener in isolation, so it supplies its
        // own lookup rather than relying on SchemaCompiler's batch-aware one:
        // every fixture class here reads cleanly through NativeEntityReader on
        // its own, with no batch to share reads against.
        $this->flattener = new ColumnFlattener(self::hierarchyFieldsOf(...));
    }

    /** @return array<string, \XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor> */
    private static function hierarchyFieldsOf(string $target): array {
        $blueprint = NativeEntityReader::read($target);
        $inherited = $blueprint->parent !== null ? self::hierarchyFieldsOf($blueprint->parent) : [];

        return [...$inherited, ...$blueprint->fields];
    }

    public function testFlattenMapsAPlainScalarFieldDirectlyToAColumn(): void {
        $blueprint = NativeEntityReader::read(FlattenerEngineBase::class);

        $result = $this->flattener->flatten($blueprint->fields, $blueprint->defaultValues);

        self::assertSame(['horsepower' => 100], $result['columns']);
        self::assertSame([], $result['external']);
    }

    public function testFlattenExcludesAnOwningReferenceFromColumnsAndRecordsItsConcreteClass(): void {
        $blueprint = NativeEntityReader::read(FlattenerCarWithOwningReference::class);

        $result = $this->flattener->flatten($blueprint->fields, $blueprint->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['engine' => FlattenerSportsEngine::class], $result['external']);
    }

    public function testFlattenAppliesTypeErasureWhenFlatteningAnEmbedsFields(): void {
        $blueprint = NativeEntityReader::read(FlattenerCarWithEmbed::class);

        $result = $this->flattener->flatten($blueprint->fields, $blueprint->defaultValues);

        // FlattenerSportsEngine's own "redline" field never shows up: the Embed is
        // declared against FlattenerEngineBase, so only its own fields flatten.
        self::assertSame(['engine_horsepower' => 100], $result['columns']);
        self::assertSame([], $result['external']);
    }

    public function testFlattenRecursesThroughEmbedOfEmbedOfEmbed(): void {
        $blueprint = NativeEntityReader::read(FlattenerCarThreeDeepEmbed::class);

        $result = $this->flattener->flatten($blueprint->fields, $blueprint->defaultValues);

        self::assertSame(['chassis_engine_piston_diameter' => 42], $result['columns']);
        self::assertSame([], $result['external']);
    }

    public function testFlattenExcludesACollectionOfScalarsAndNamesItsItemKind(): void {
        $blueprint = NativeEntityReader::read(FlattenerProductWithTags::class);

        $result = $this->flattener->flatten($blueprint->fields, $blueprint->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['tags' => 'Scalar'], $result['external']);
    }

    public function testFlattenExcludesACollectionOfOwningReferencesAndNamesItsItemTarget(): void {
        $blueprint = NativeEntityReader::read(FlattenerGarage::class);

        $result = $this->flattener->flatten($blueprint->fields, $blueprint->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['engines' => FlattenerEngineBase::class], $result['external']);
    }

    public function testFlattenExcludesACollectionOfEmbedsAndNamesItsItemTarget(): void {
        $blueprint = NativeEntityReader::read(FlattenerGarageOfEmbeddedPistons::class);

        $result = $this->flattener->flatten($blueprint->fields, $blueprint->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['pistons' => FlattenerPiston::class], $result['external']);
    }

    public function testFlattenFindsAnOwningReferenceNestedInsideAnEmbedAtItsPrefixedPath(): void {
        $blueprint = NativeEntityReader::read(FlattenerCarEmbedContainingOwned::class);

        $result = $this->flattener->flatten($blueprint->fields, $blueprint->defaultValues);

        self::assertSame([], $result['columns']);
        self::assertSame(['chassis_battery' => FlattenerBattery::class], $result['external']);
    }

}
