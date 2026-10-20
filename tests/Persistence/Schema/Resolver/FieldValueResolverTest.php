<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Resolver;

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
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

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

    #[Embed]
    private ResolverPiston $piston;

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
    #[Embed]
    private ResolverEngine $engine;

    public function __construct() {
        $this->engine = new ResolverEngine();
    }
}

#[Entity]
class ResolverCar
{
    #[OwningReference]
    private ?ResolverEngine $engine = null;
}

#[Entity]
final class ResolverAudiCar extends ResolverCar
{
}

#[Entity]
class ResolverGarage
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: ResolverPiston::class)]
    private array $pistons = [];

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: ResolverEngine::class)]
    private array $engines = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String)]
    private array $tags = [];
}

#[Entity]
final class ResolverBigGarage extends ResolverGarage
{
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
        $stores = new InMemorySchemaStores();
        $registry = $stores->registry;
        foreach ([
            ResolverPiston::class,
            ResolverEngine::class,
            ResolverSportsEngine::class,
            ResolverChassis::class,
            ResolverCar::class,
            ResolverAudiCar::class,
            ResolverGarage::class,
            ResolverBigGarage::class,
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

        $this->store = $stores->values;
        $this->resolver = new FieldValueResolver($registry, $this->store);
    }

    private function resolve(string $identifier, string $path): mixed {
        return $this->resolver->resolve($identifier, FieldPath::fromString($path));
    }

    public function testReturnsAnIdentifiersOwnStoredValue(): void {
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(300, $this->resolve(ResolverEngine::class, 'horsepower'));
    }

    public function testFallsBackToTheParent(): void {
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(300, $this->resolve(ResolverSportsEngine::class, 'horsepower'));
    }

    public function testAChildsOwnValueWinsOverItsParents(): void {
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));
        $this->store->save(ResolverSportsEngine::class, FieldValues::fromArray(['horsepower' => 500]));

        self::assertSame(500, $this->resolve(ResolverSportsEngine::class, 'horsepower'));
    }

    public function testAnEmbedFallsBackToItsTarget(): void {
        $this->store->save(ResolverPiston::class, FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(10, $this->resolve(ResolverEngine::class, 'piston:diameter'));
    }

    public function testAnEmbedChainFallsBackOneTargetAtATime(): void {
        $this->store->save(ResolverPiston::class, FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(10, $this->resolve(ResolverChassis::class, 'engine:piston:diameter'));
    }

    public function testAnEmbedOverrideWinsOverItsTarget(): void {
        $this->store->save(ResolverPiston::class, FieldValues::fromArray(['diameter' => 10]));
        $this->store->save(ResolverChassis::class, FieldValues::fromArray(['engine:piston:diameter' => 12]));

        self::assertSame(12, $this->resolve(ResolverChassis::class, 'engine:piston:diameter'));
    }

    public function testAnOwningReferenceFallsBackThroughTheClassItHolds(): void {
        $this->store->save(ResolverCar::class, FieldValues::fromArray(['engine' => ResolverSportsEngine::class]));
        $this->store->save(ResolverSportsEngine::class, FieldValues::fromArray(['boost' => 2]));
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));

        // Through ResolverSportsEngine's own row, then up to its parent's.
        self::assertSame(2, $this->resolve(ResolverCar::class, 'engine.boost'));
        self::assertSame(300, $this->resolve(ResolverCar::class, 'engine.horsepower'));
    }

    public function testAnOwnersOverrideWinsOverTheClassItHolds(): void {
        $this->store->save(ResolverCar::class, FieldValues::fromArray(['engine' => ResolverSportsEngine::class, 'engine.horsepower' => 500]));
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(500, $this->resolve(ResolverCar::class, 'engine.horsepower'));
    }

    public function testAnOwnersParentsOverrideWinsOverTheClassItHolds(): void {
        // ResolverAudiCar stores nothing: both the class it holds and the
        // override come from ResolverCar, ahead of the engine's own default.
        $this->store->save(ResolverCar::class, FieldValues::fromArray(['engine' => ResolverSportsEngine::class, 'engine.horsepower' => 400]));
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(400, $this->resolve(ResolverAudiCar::class, 'engine.horsepower'));
    }

    public function testAnEmbedInsideAnOwnedObjectFallsBackThroughBoth(): void {
        $this->store->save(ResolverCar::class, FieldValues::fromArray(['engine' => ResolverEngine::class]));
        $this->store->save(ResolverPiston::class, FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(10, $this->resolve(ResolverCar::class, 'engine.piston:diameter'));
    }

    public function testAnOwningReferenceHoldingNothingThrows(): void {
        $this->store->save(ResolverCar::class, FieldValues::fromArray(['engine' => null]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/holds nothing/');

        $this->resolve(ResolverCar::class, 'engine.horsepower');
    }

    public function testAnEmbedCollectionItemFallsBackToTheItemTarget(): void {
        $this->store->save(ResolverGarage::class, FieldValues::fromArray(['pistons' => 1]));
        $this->store->save(ResolverPiston::class, FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(10, $this->resolve(ResolverGarage::class, 'pistons.0.diameter'));
    }

    public function testAnOwnedCollectionItemFallsBackThroughTheClassItHolds(): void {
        $this->store->save(ResolverGarage::class, FieldValues::fromArray(['engines' => 1, 'engines.0' => ResolverEngine::class]));
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(300, $this->resolve(ResolverGarage::class, 'engines.0.horsepower'));
    }

    public function testAStoredScalarCollectionItemIsReturned(): void {
        $this->store->save(ResolverGarage::class, FieldValues::fromArray(['tags' => 2, 'tags.1' => 'blue']));

        self::assertSame('blue', $this->resolve(ResolverGarage::class, 'tags.1'));
    }

    public function testACollectionItemPastTheStoredCountThrows(): void {
        $this->store->save(ResolverGarage::class, FieldValues::fromArray(['pistons' => 1]));
        $this->store->save(ResolverPiston::class, FieldValues::fromArray(['diameter' => 10]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has only 1 item/');

        $this->resolve(ResolverGarage::class, 'pistons.1.diameter');
    }

    public function testACollectionTheChildStoresNeverFallsBackToTheParentsItems(): void {
        $this->store->save(ResolverGarage::class, FieldValues::fromArray(['pistons' => 2, 'pistons.0.diameter' => 99]));
        $this->store->save(ResolverBigGarage::class, FieldValues::fromArray(['pistons' => 1]));
        $this->store->save(ResolverPiston::class, FieldValues::fromArray(['diameter' => 10]));

        // ResolverBigGarage owns its collection: item 0 falls back to
        // ResolverPiston, never to ResolverGarage's own item 0.
        self::assertSame(1, $this->resolve(ResolverBigGarage::class, 'pistons'));
        self::assertSame(10, $this->resolve(ResolverBigGarage::class, 'pistons.0.diameter'));
    }

    public function testACollectionTheChildDoesNotStoreIsInheritedWhole(): void {
        $this->store->save(ResolverGarage::class, FieldValues::fromArray(['pistons' => 2, 'pistons.0.diameter' => 99]));
        $this->store->save(ResolverPiston::class, FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(2, $this->resolve(ResolverBigGarage::class, 'pistons'));
        self::assertSame(99, $this->resolve(ResolverBigGarage::class, 'pistons.0.diameter'));
        self::assertSame(10, $this->resolve(ResolverBigGarage::class, 'pistons.1.diameter'));
    }

    public function testResolveFallbackSkipsOnlyThatKey(): void {
        $this->store->save(ResolverCar::class, FieldValues::fromArray(['engine' => ResolverSportsEngine::class, 'engine.horsepower' => 500]));
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));

        // The class ResolverCar holds still counts; only its own horsepower
        // override is skipped.
        self::assertSame(300, $this->resolver->resolveFallback(ResolverCar::class, FieldPath::fromString('engine.horsepower')));
    }

    public function testResolveWithoutSubtreeSkipsTheWholeCollection(): void {
        $this->store->save(ResolverGarage::class, FieldValues::fromArray(['pistons' => 2, 'pistons.0.diameter' => 99]));
        $this->store->save(ResolverBigGarage::class, FieldValues::fromArray(['pistons' => 1, 'pistons.0.diameter' => 12]));

        $pistons = FieldPath::fromString('pistons');

        self::assertSame(2, $this->resolver->resolveWithoutSubtree(ResolverBigGarage::class, $pistons, $pistons));
        self::assertSame(99, $this->resolver->resolveWithoutSubtree(ResolverBigGarage::class, FieldPath::fromString('pistons.0.diameter'), $pistons));
    }

    public function testAResolverWithABatchReadsTheBatchAheadOfTheStore(): void {
        $this->store->save(ResolverEngine::class, FieldValues::fromArray(['horsepower' => 300]));

        $batched = $this->resolver->withBatch([], [ResolverEngine::class => FieldValues::fromArray(['horsepower' => 1])]);

        self::assertSame(1, $batched->resolve(ResolverEngine::class, FieldPath::fromString('horsepower')));
    }

    public function testAStoredNullIsAValueNotAMiss(): void {
        $this->store->save(ResolverCar::class, FieldValues::fromArray(['engine' => null]));

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
        $this->store->save(ResolverGarage::class, FieldValues::fromArray(['pistons' => 1, 'engines' => 1, 'tags' => 1]));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($message);

        $this->resolve($identifier, $path);
    }
}
