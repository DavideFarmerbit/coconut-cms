<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Values\Resolver;

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
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
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

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: ResolverEngine::class, itemNullability: Nullability::Nullable)]
    private array $engines = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
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
        return $this->resolver->resolve(Subject::of($identifier), FieldPath::fromString($path));
    }

    public function testReturnsAnIdentifiersOwnStoredValue(): void {
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(300, $this->resolve('default:resolver_engine', 'horsepower'));
    }

    public function testFallsBackToTheParent(): void {
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(300, $this->resolve('default:resolver_sports_engine', 'horsepower'));
    }

    public function testAChildsOwnValueWinsOverItsParents(): void {
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));
        $this->store->save('default:resolver_sports_engine', FieldValues::fromArray(['horsepower' => 500]));

        self::assertSame(500, $this->resolve('default:resolver_sports_engine', 'horsepower'));
    }

    public function testAnEmbedFallsBackToItsTarget(): void {
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(10, $this->resolve('default:resolver_engine', 'piston:diameter'));
    }

    public function testAnEmbedChainFallsBackOneTargetAtATime(): void {
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(10, $this->resolve('default:resolver_chassis', 'engine:piston:diameter'));
    }

    public function testAnEmbedOverrideWinsOverItsTarget(): void {
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));
        $this->store->save('default:resolver_chassis', FieldValues::fromArray(['engine:piston:diameter' => 12]));

        self::assertSame(12, $this->resolve('default:resolver_chassis', 'engine:piston:diameter'));
    }

    public function testAnOwningReferenceFallsBackThroughTheClassItHolds(): void {
        $this->store->save('default:resolver_car', FieldValues::fromArray(['engine' => 'default:resolver_sports_engine']));
        $this->store->save('default:resolver_sports_engine', FieldValues::fromArray(['boost' => 2]));
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));

        // Through ResolverSportsEngine's own row, then up to its parent's.
        self::assertSame(2, $this->resolve('default:resolver_car', 'engine.boost'));
        self::assertSame(300, $this->resolve('default:resolver_car', 'engine.horsepower'));
    }

    public function testAnOwnersOverrideWinsOverTheClassItHolds(): void {
        $this->store->save('default:resolver_car', FieldValues::fromArray(['engine' => 'default:resolver_sports_engine', 'engine.horsepower' => 500]));
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(500, $this->resolve('default:resolver_car', 'engine.horsepower'));
    }

    public function testAnOwnersParentsOverrideWinsOverTheClassItHolds(): void {
        // ResolverAudiCar stores nothing: both the class it holds and the
        // override come from ResolverCar, ahead of the engine's own default.
        $this->store->save('default:resolver_car', FieldValues::fromArray(['engine' => 'default:resolver_sports_engine', 'engine.horsepower' => 400]));
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(400, $this->resolve('default:resolver_audi_car', 'engine.horsepower'));
    }

    public function testAnEmbedInsideAnOwnedObjectFallsBackThroughBoth(): void {
        $this->store->save('default:resolver_car', FieldValues::fromArray(['engine' => 'default:resolver_engine']));
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(10, $this->resolve('default:resolver_car', 'engine.piston:diameter'));
    }

    public function testAnOwningReferenceHoldingNothingThrows(): void {
        $this->store->save('default:resolver_car', FieldValues::fromArray(['engine' => null]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/holds nothing/');

        $this->resolve('default:resolver_car', 'engine.horsepower');
    }

    public function testAnEmbedCollectionItemFallsBackToTheItemTarget(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['pistons' => 1]));
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(10, $this->resolve('default:resolver_garage', 'pistons.0.diameter'));
    }

    public function testAnOwnedCollectionItemFallsBackThroughTheClassItHolds(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['engines' => 1, 'engines.0' => 'default:resolver_engine']));
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));

        self::assertSame(300, $this->resolve('default:resolver_garage', 'engines.0.horsepower'));
    }

    public function testAStoredScalarCollectionItemIsReturned(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['tags' => 2, 'tags.1' => 'blue']));

        self::assertSame('blue', $this->resolve('default:resolver_garage', 'tags.1'));
    }

    public function testACollectionItemPastTheStoredCountThrows(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['pistons' => 1]));
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has only 1 item/');

        $this->resolve('default:resolver_garage', 'pistons.1.diameter');
    }

    public function testACollectionTheChildStoresNeverFallsBackToTheParentsItems(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['pistons' => 2, 'pistons.0.diameter' => 99]));
        $this->store->save('default:resolver_big_garage', FieldValues::fromArray(['pistons' => 1]));
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));

        // ResolverBigGarage owns its collection: item 0 falls back to
        // ResolverPiston, never to ResolverGarage's own item 0.
        self::assertSame(1, $this->resolve('default:resolver_big_garage', 'pistons'));
        self::assertSame(10, $this->resolve('default:resolver_big_garage', 'pistons.0.diameter'));
    }

    public function testACollectionTheChildDoesNotStoreIsInheritedWhole(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['pistons' => 2, 'pistons.0.diameter' => 99]));
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));

        self::assertSame(2, $this->resolve('default:resolver_big_garage', 'pistons'));
        self::assertSame(99, $this->resolve('default:resolver_big_garage', 'pistons.0.diameter'));
        self::assertSame(10, $this->resolve('default:resolver_big_garage', 'pistons.1.diameter'));
    }

    public function testResolveFallbackSkipsOnlyThatKey(): void {
        $this->store->save('default:resolver_car', FieldValues::fromArray(['engine' => 'default:resolver_sports_engine', 'engine.horsepower' => 500]));
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));

        // The class ResolverCar holds still counts; only its own horsepower
        // override is skipped.
        self::assertSame(300, $this->resolver->resolveFallback(Subject::of('default:resolver_car'), FieldPath::fromString('engine.horsepower')));
    }

    public function testResolveWithoutSubtreeSkipsTheWholeCollection(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['pistons' => 2, 'pistons.0.diameter' => 99]));
        $this->store->save('default:resolver_big_garage', FieldValues::fromArray(['pistons' => 1, 'pistons.0.diameter' => 12]));

        $pistons = FieldPath::fromString('pistons');

        self::assertSame(2, $this->resolver->resolveWithoutSubtree(Subject::of('default:resolver_big_garage'), $pistons, $pistons));
        self::assertSame(99, $this->resolver->resolveWithoutSubtree(Subject::of('default:resolver_big_garage'), FieldPath::fromString('pistons.0.diameter'), $pistons));
    }

    public function testAResolverWithABatchReadsTheBatchAheadOfTheStore(): void {
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));

        $batched = $this->resolver->withBatch([], ['default:resolver_engine' => FieldValues::fromArray(['horsepower' => 1])]);

        self::assertSame(1, $batched->resolve(Subject::of('default:resolver_engine'), FieldPath::fromString('horsepower')));
    }

    public function testAnInstancesOwnValueWinsOverItsClass(): void {
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));
        $instance = Subject::ofInstance('default:resolver_engine', FieldValues::fromArray(['horsepower' => 450]));

        self::assertSame(450, $this->resolver->resolve($instance, FieldPath::fromString('horsepower')));
    }

    public function testAnInstanceFallsBackToItsClassAndItsClasssParents(): void {
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));
        $instance = Subject::ofInstance('default:resolver_sports_engine', FieldValues::empty());

        self::assertSame(300, $this->resolver->resolve($instance, FieldPath::fromString('horsepower')));
        self::assertSame(10, $this->resolver->resolve($instance, FieldPath::fromString('piston:diameter')));
    }

    public function testAnInstanceHoldingAnotherOwnedClassStillGetsItsClasssOverride(): void {
        // The class pins engine.horsepower on whatever engine it holds; the
        // instance swaps in a sports engine but sets no horsepower itself.
        $this->store->save('default:resolver_car', FieldValues::fromArray(['engine' => 'default:resolver_engine', 'engine.horsepower' => 500]));
        $this->store->save('default:resolver_sports_engine', FieldValues::fromArray(['boost' => 3]));
        $instance = Subject::ofInstance('default:resolver_car', FieldValues::fromArray(['engine' => 'default:resolver_sports_engine']));

        self::assertSame(500, $this->resolver->resolve($instance, FieldPath::fromString('engine.horsepower')));
        self::assertSame(3, $this->resolver->resolve($instance, FieldPath::fromString('engine.boost')));
    }

    public function testACollectionTheInstanceHoldsNeverFallsBackToTheClasssItems(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['pistons' => 2, 'pistons.0.diameter' => 99]));
        $this->store->save('default:resolver_piston', FieldValues::fromArray(['diameter' => 10]));
        $instance = Subject::ofInstance('default:resolver_garage', FieldValues::fromArray(['pistons' => 1]));

        self::assertSame(1, $this->resolver->resolve($instance, FieldPath::fromString('pistons')));
        self::assertSame(10, $this->resolver->resolve($instance, FieldPath::fromString('pistons.0.diameter')));
    }

    public function testAnInstancesFallbackSkipsOnlyTheInstancesOwnValue(): void {
        // The class holds the same path too: only the instance's own value is
        // skipped, so the fallback is the class's.
        $this->store->save('default:resolver_engine', FieldValues::fromArray(['horsepower' => 300]));
        $instance = Subject::ofInstance('default:resolver_engine', FieldValues::fromArray(['horsepower' => 450]));

        self::assertSame(300, $this->resolver->resolveFallback($instance, FieldPath::fromString('horsepower')));
    }

    public function testAnInstanceWithoutItsCollectionFallsBackToTheClasssWholeCollection(): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['pistons' => 2, 'pistons.0.diameter' => 99]));
        $instance = Subject::ofInstance('default:resolver_garage', FieldValues::fromArray(['pistons' => 1, 'pistons.0.diameter' => 12]));
        $pistons = FieldPath::fromString('pistons');

        self::assertSame(2, $this->resolver->resolveWithoutSubtree($instance, $pistons, $pistons));
        self::assertSame(99, $this->resolver->resolveWithoutSubtree($instance, FieldPath::fromString('pistons.0.diameter'), $pistons));
    }

    public function testAStoredNullIsAValueNotAMiss(): void {
        $this->store->save('default:resolver_car', FieldValues::fromArray(['engine' => null]));

        self::assertNull($this->resolve('default:resolver_car', 'engine'));
    }

    /** @return array<string, array{class-string, string, string}> */
    public static function unresolvablePaths(): array {
        return [
            'nothing stored anywhere' => ['default:resolver_engine', 'horsepower', '/Nothing holds a value/'],
            'unknown field' => ['default:resolver_engine', 'torque', '/has no field "torque"/'],
            'embed traversed with "."' => ['default:resolver_engine', 'piston.diameter', '/must be followed by ":"/'],
            'owned field embedded with ":"' => ['default:resolver_car', 'engine:horsepower', '/must be followed by "\."/'],
            'scalar with a tail' => ['default:resolver_engine', 'horsepower.x', '/has no fields of its own/'],
            'collection without an index' => ['default:resolver_garage', 'pistons.diameter', '/must be followed by an item index/'],
            'scalar collection item with a tail' => ['default:resolver_garage', 'tags.0.x', '/have no fields of their own/'],
        ];
    }

    #[DataProvider('unresolvablePaths')]
    public function testUnresolvablePathThrows(string $identifier, string $path, string $message): void {
        $this->store->save('default:resolver_garage', FieldValues::fromArray(['pistons' => 1, 'engines' => 1, 'tags' => 1]));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($message);

        $this->resolve($identifier, $path);
    }
}
