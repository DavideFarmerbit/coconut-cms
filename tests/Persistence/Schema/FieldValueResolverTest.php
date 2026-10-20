<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaRegistry;

#[Entity]
final class ResolverPiston
{
    #[Scalar(ScalarType::Int)]
    private int $diameter = 10;
}

#[Entity]
class ResolverEngine
{
    #[Scalar(ScalarType::Int)]
    private int $horsepower = 300;

    #[Embed(ResolverPiston::class)]
    private object $piston;

    public function __construct() {
        $this->piston = new ResolverPiston();
    }
}

#[Entity]
final class ResolverSportsEngine extends ResolverEngine
{
    #[Scalar(ScalarType::Int)]
    private int $boost = 2;
}

#[Entity]
final class ResolverChassis
{
    #[Embed(ResolverEngine::class)]
    private object $engine;

    public function __construct() {
        $this->engine = new ResolverEngine();
    }
}

#[Entity]
class ResolverCar
{
    #[OwningReference(ResolverEngine::class)]
    private ?object $engine = null;
}

#[Entity]
final class ResolverAudiCar extends ResolverCar
{
}

#[Entity]
final class ResolverGarage
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: ResolverPiston::class)]
    private array $pistons = [];

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: ResolverEngine::class)]
    private array $engines = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String)]
    private array $tags = [];
}

/**
 * Schemas are registered directly and values saved by hand, sparse, rather
 * than through SchemaCompiler: the compiler stores every value, so nothing
 * would ever need to fall back.
 */
final class FieldValueResolverTest extends TestCase
{
    private SchemaFieldValueStore $store;
    private FieldValueResolver $resolver;

    protected function setUp(): void {
        $registry = new SchemaRegistry();
        foreach ([
            ResolverPiston::class,
            ResolverEngine::class,
            ResolverSportsEngine::class,
            ResolverChassis::class,
            ResolverCar::class,
            ResolverAudiCar::class,
            ResolverGarage::class,
        ] as $class) {
            $blueprint = NativeEntityReader::read($class);
            $registry->register(new EntitySchema(
                identifier: $blueprint->identifier,
                parent: $blueprint->parent,
                table: $blueprint->table,
                editorExtensible: $blueprint->editorExtensible,
                fields: $blueprint->fields,
                fieldPermissions: $blueprint->fieldPermissions,
                fieldValidators: $blueprint->fieldValidators,
                uniqueGroups: $blueprint->uniqueGroups,
                prototypeValidators: $blueprint->prototypeValidators,
            ));
        }

        $this->store = new SchemaFieldValueStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->store->createTable();
        $this->resolver = new FieldValueResolver($registry, $this->store);
    }

    private function resolve(string $identifier, string $path): mixed {
        return $this->resolver->resolve($identifier, FieldPath::fromString($path));
    }

    public function testReturnsAnIdentifiersOwnStoredValue(): void {
        $this->store->save(ResolverEngine::class, ['horsepower' => 300]);

        self::assertSame(300, $this->resolve(ResolverEngine::class, 'horsepower'));
    }

    public function testFallsBackToTheParent(): void {
        $this->store->save(ResolverEngine::class, ['horsepower' => 300]);

        self::assertSame(300, $this->resolve(ResolverSportsEngine::class, 'horsepower'));
    }

    public function testAChildsOwnValueWinsOverItsParents(): void {
        $this->store->save(ResolverEngine::class, ['horsepower' => 300]);
        $this->store->save(ResolverSportsEngine::class, ['horsepower' => 500]);

        self::assertSame(500, $this->resolve(ResolverSportsEngine::class, 'horsepower'));
    }

    public function testAnEmbedFallsBackToItsTarget(): void {
        $this->store->save(ResolverPiston::class, ['diameter' => 10]);

        self::assertSame(10, $this->resolve(ResolverEngine::class, 'piston:diameter'));
    }

    public function testAnEmbedChainFallsBackOneTargetAtATime(): void {
        $this->store->save(ResolverPiston::class, ['diameter' => 10]);

        self::assertSame(10, $this->resolve(ResolverChassis::class, 'engine:piston:diameter'));
    }

    public function testAnEmbedOverrideWinsOverItsTarget(): void {
        $this->store->save(ResolverPiston::class, ['diameter' => 10]);
        $this->store->save(ResolverChassis::class, ['engine:piston:diameter' => 12]);

        self::assertSame(12, $this->resolve(ResolverChassis::class, 'engine:piston:diameter'));
    }

    public function testAnOwningReferenceFallsBackThroughTheClassItHolds(): void {
        $this->store->save(ResolverCar::class, ['engine' => ResolverSportsEngine::class]);
        $this->store->save(ResolverSportsEngine::class, ['boost' => 2]);
        $this->store->save(ResolverEngine::class, ['horsepower' => 300]);

        // Through ResolverSportsEngine's own row, then up to its parent's.
        self::assertSame(2, $this->resolve(ResolverCar::class, 'engine.boost'));
        self::assertSame(300, $this->resolve(ResolverCar::class, 'engine.horsepower'));
    }

    public function testAnOwnersOverrideWinsOverTheClassItHolds(): void {
        $this->store->save(ResolverCar::class, ['engine' => ResolverSportsEngine::class, 'engine.horsepower' => 500]);
        $this->store->save(ResolverEngine::class, ['horsepower' => 300]);

        self::assertSame(500, $this->resolve(ResolverCar::class, 'engine.horsepower'));
    }

    public function testAnOwnersParentsOverrideWinsOverTheClassItHolds(): void {
        // ResolverAudiCar stores nothing: both the class it holds and the
        // override come from ResolverCar, ahead of the engine's own default.
        $this->store->save(ResolverCar::class, ['engine' => ResolverSportsEngine::class, 'engine.horsepower' => 400]);
        $this->store->save(ResolverEngine::class, ['horsepower' => 300]);

        self::assertSame(400, $this->resolve(ResolverAudiCar::class, 'engine.horsepower'));
    }

    public function testAnEmbedInsideAnOwnedObjectFallsBackThroughBoth(): void {
        $this->store->save(ResolverCar::class, ['engine' => ResolverEngine::class]);
        $this->store->save(ResolverPiston::class, ['diameter' => 10]);

        self::assertSame(10, $this->resolve(ResolverCar::class, 'engine.piston:diameter'));
    }

    public function testAnOwningReferenceHoldingNothingThrows(): void {
        $this->store->save(ResolverCar::class, ['engine' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/holds nothing/');

        $this->resolve(ResolverCar::class, 'engine.horsepower');
    }

    public function testAnEmbedCollectionItemFallsBackToTheItemTarget(): void {
        $this->store->save(ResolverPiston::class, ['diameter' => 10]);

        self::assertSame(10, $this->resolve(ResolverGarage::class, 'pistons.0.diameter'));
    }

    public function testAnOwnedCollectionItemFallsBackThroughTheClassItHolds(): void {
        $this->store->save(ResolverGarage::class, ['engines.0' => ResolverEngine::class]);
        $this->store->save(ResolverEngine::class, ['horsepower' => 300]);

        self::assertSame(300, $this->resolve(ResolverGarage::class, 'engines.0.horsepower'));
    }

    public function testAStoredScalarCollectionItemIsReturned(): void {
        $this->store->save(ResolverGarage::class, ['tags.1' => 'blue']);

        self::assertSame('blue', $this->resolve(ResolverGarage::class, 'tags.1'));
    }

    public function testAStoredNullIsAValueNotAMiss(): void {
        $this->store->save(ResolverCar::class, ['engine' => null]);

        self::assertNull($this->resolve(ResolverCar::class, 'engine'));
    }

    /** @return array<string, array{class-string, string, string}> */
    public static function unresolvablePaths(): array {
        return [
            'nothing stored anywhere' => [ResolverEngine::class, 'horsepower', '/Nothing holds a value/'],
            'unknown field' => [ResolverEngine::class, 'torque', '/has no field "torque"/'],
            'embed traversed with "."' => [ResolverEngine::class, 'piston.diameter', '/must be followed by ":"/'],
            'owned field embedded with ":"' => [ResolverCar::class, 'engine:horsepower', '/must be followed by "\."/'],
            'scalar with a tail' => [ResolverEngine::class, 'horsepower.x', '/has no fields of its own/'],
            'collection without an index' => [ResolverGarage::class, 'pistons.diameter', '/must be followed by an item index/'],
            'scalar collection item with a tail' => [ResolverGarage::class, 'tags.0.x', '/have no fields of their own/'],
        ];
    }

    #[DataProvider('unresolvablePaths')]
    public function testUnresolvablePathThrows(string $identifier, string $path, string $message): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($message);

        $this->resolve($identifier, $path);
    }
}
