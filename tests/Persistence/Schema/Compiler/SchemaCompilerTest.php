<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Compiler;

use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaRegistry;

#[Entity]
class CompilerRoot
{
    #[Scalar(ScalarType::String)]
    private string $name = '';
}

#[Entity]
final class CompilerChild extends CompilerRoot
{
    #[Scalar(ScalarType::String)]
    private string $extra = '';
}

#[Entity]
final class CompilerEmbedTarget
{
    #[Scalar(ScalarType::String)]
    private string $value = '';
}

#[Entity]
final class CompilerEmbedder
{
    #[Embed(CompilerEmbedTarget::class)]
    private object $target;

    public function __construct() {
        $this->target = new CompilerEmbedTarget();
    }
}

#[Entity]
final class CompilerDeepEmbedMiddle
{
    #[Embed(CompilerEmbedTarget::class)]
    private object $target;

    public function __construct() {
        $this->target = new CompilerEmbedTarget();
    }
}

#[Entity]
final class CompilerDeepEmbedOuter
{
    #[Embed(CompilerDeepEmbedMiddle::class)]
    private object $middle;

    public function __construct() {
        $this->middle = new CompilerDeepEmbedMiddle();
    }
}

#[Entity]
final class CompilerCollectionEmbedder
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: CompilerEmbedTarget::class)]
    private array $items = [];
}

#[Entity]
final class CompilerCycleA
{
    #[Embed(CompilerCycleB::class)]
    private ?object $b = null;
}

#[Entity]
final class CompilerCycleB
{
    #[Embed(CompilerCycleA::class)]
    private ?object $a = null;
}

#[Entity]
final class CompilerNode
{
    #[OwningReference(CompilerNode::class)]
    private ?object $child = null;
}

#[Entity]
final class CompilerPiston
{
    #[Scalar(ScalarType::Int)]
    private int $diameter = 10;
}

#[Entity]
class CompilerOwnedEngine
{
    #[Scalar(ScalarType::Int)]
    private int $horsepower;

    #[Embed(CompilerPiston::class)]
    private object $piston;

    public function __construct(int $horsepower = 300) {
        $this->horsepower = $horsepower;
        $this->piston = new CompilerPiston();
    }
}

#[Entity]
final class CompilerSportsEngine extends CompilerOwnedEngine
{
    #[Scalar(ScalarType::Int)]
    private int $boost = 2;
}

#[Entity]
final class CompilerOwner
{
    #[OwningReference(CompilerOwnedEngine::class)]
    private ?object $engine;

    public function __construct() {
        $this->engine = new CompilerSportsEngine(500);
    }
}

#[Entity]
final class CompilerCollectionOwner
{
    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String)]
    private array $tags = ['a', 'b'];

    #[Collection(itemKind: FieldKind::Embed, itemTarget: CompilerPiston::class)]
    private array $pistons;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: CompilerOwnedEngine::class)]
    private array $engines;

    public function __construct() {
        $this->pistons = [new CompilerPiston()];
        $this->engines = [new CompilerOwnedEngine(400)];
    }
}

#[Entity]
final class CompilerOwnerOfAbsentTarget
{
    #[OwningReference(CompilerPiston::class)]
    private ?object $piston = null;
}

#[Entity]
final class CompilerCollectionOwnerOfAbsentTarget
{
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: CompilerPiston::class)]
    private array $pistons = [];
}

#[Entity]
final class CompilerReferrerOfAbsentTarget
{
    #[Reference(CompilerPiston::class)]
    private ?object $piston = null;
}

#[Entity]
final class CompilerCollectionReferrerOfAbsentTarget
{
    #[Collection(itemKind: FieldKind::Reference, itemTarget: CompilerPiston::class)]
    private array $pistons = [];
}

#[Entity]
final class CompilerOwningCycleA
{
    #[OwningReference(CompilerOwningCycleB::class)]
    private ?object $b = null;
}

#[Entity]
final class CompilerOwningCycleB
{
    #[OwningReference(CompilerOwningCycleA::class)]
    private ?object $a = null;
}

#[Entity]
final class CompilerOwnerOfUnrelatedClass
{
    #[OwningReference(CompilerOwnedEngine::class)]
    private ?object $engine;

    public function __construct() {
        $this->engine = new CompilerPiston();
    }
}

final class SchemaCompilerTest extends TestCase
{
    private SchemaCompiler $compiler;

    protected function setUp(): void {
        $this->compiler = self::newCompiler(new SchemaRegistry());
    }

    private static function newCompiler(SchemaRegistry $registry): SchemaCompiler {
        $valueStore = new SchemaFieldValueStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $valueStore->createTable();

        return new SchemaCompiler($registry, $valueStore);
    }

    /**
     * Stands in for whatever a real caller does before handing a batch to
     * compile(): reading each native class's own blueprint. compile() itself
     * never does this.
     * @param class-string[] $classes
     * @return array<string, \XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint>
     */
    private static function blueprintsOf(array $classes): array {
        $blueprints = [];
        foreach ($classes as $class) {
            $blueprints[$class] = NativeEntityReader::read($class);
        }

        return $blueprints;
    }

    public function testCompileOrdersParentBeforeChild(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerChild::class, CompilerRoot::class])));

        self::assertSame([CompilerRoot::class, CompilerChild::class], $order);
    }

    public function testCompileOrdersEmbedTargetBeforeEmbedder(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerEmbedder::class, CompilerEmbedTarget::class])));

        self::assertSame([CompilerEmbedTarget::class, CompilerEmbedder::class], $order);
    }

    public function testCompileOrdersATransitiveEmbedChainCorrectly(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([
            CompilerDeepEmbedOuter::class,
            CompilerDeepEmbedMiddle::class,
            CompilerEmbedTarget::class,
        ])));

        self::assertSame([
            CompilerEmbedTarget::class,
            CompilerDeepEmbedMiddle::class,
            CompilerDeepEmbedOuter::class,
        ], $order);
    }

    public function testCompileOrdersCollectionEmbedItemTargetBeforeEmbedder(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerCollectionEmbedder::class, CompilerEmbedTarget::class])));

        self::assertSame([CompilerEmbedTarget::class, CompilerCollectionEmbedder::class], $order);
    }

    public function testCompileLeavesASelfReferencingOwningReferenceAlone(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerNode::class])));

        self::assertSame([CompilerNode::class], $order);
    }

    public function testCompileWithMissingParentThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile(self::blueprintsOf([CompilerChild::class]));
    }

    public function testCompileWithMissingEmbedTargetThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile(self::blueprintsOf([CompilerEmbedder::class]));
    }

    public function testCompileWithEmbedCycleThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->compiler->compile(self::blueprintsOf([CompilerCycleA::class, CompilerCycleB::class]));
    }

    public function testCompileResolvesADependencyAlreadyRegisteredFromAnEarlierCompileCall(): void {
        $this->compiler->compile(self::blueprintsOf([CompilerRoot::class]));

        // CompilerRoot isn't in this second batch at all, but it's already
        // registered from the first compile() call, so this must not throw.
        $schemas = $this->compiler->compile(self::blueprintsOf([CompilerChild::class]));

        self::assertSame([CompilerChild::class], array_keys($schemas));
    }

    public function testCompileRegistersEveryCompiledSchemaWithTheRegistry(): void {
        $registry = new SchemaRegistry();
        $compiler = self::newCompiler($registry);

        $compiler->compile(self::blueprintsOf([CompilerEmbedTarget::class]));

        self::assertTrue($registry->has(CompilerEmbedTarget::class));
    }

    /** @param class-string[] $classes */
    private static function compileIntoFreshStore(array $classes): SchemaFieldValueStore {
        $valueStore = new SchemaFieldValueStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $valueStore->createTable();
        (new SchemaCompiler(new SchemaRegistry(), $valueStore))->compile(self::blueprintsOf($classes));

        return $valueStore;
    }

    /**
     * Sorted by key, so assertions don't depend on the order the database
     * hands rows back in.
     * @return array<string, mixed>
     */
    private static function loadSorted(SchemaFieldValueStore $valueStore, string $identifier): array {
        $values = $valueStore->load($identifier);
        ksort($values, SORT_STRING);

        return $values;
    }

    public function testCompileWritesAClassOwnScalarValues(): void {
        $valueStore = self::compileIntoFreshStore([CompilerEmbedTarget::class]);

        self::assertSame(['value' => ''], self::loadSorted($valueStore, CompilerEmbedTarget::class));
    }

    public function testCompileJoinsAnEmbedsFieldsWithAnUnderscore(): void {
        $valueStore = self::compileIntoFreshStore([CompilerEmbedder::class, CompilerEmbedTarget::class]);

        self::assertSame(['target_value' => ''], self::loadSorted($valueStore, CompilerEmbedder::class));
    }

    public function testCompileStoresANullOwningReferenceAsANullClass(): void {
        $valueStore = self::compileIntoFreshStore([CompilerNode::class]);

        self::assertSame(['child' => null], self::loadSorted($valueStore, CompilerNode::class));
    }

    public function testCompileTraversesAnOwningReferenceWithADot(): void {
        $valueStore = self::compileIntoFreshStore([
            CompilerOwner::class,
            CompilerOwnedEngine::class,
            CompilerSportsEngine::class,
            CompilerPiston::class,
        ]);

        // The bare key holds the concrete class, here a subclass of the
        // declared target, and everything the owner's default instance set on
        // it hangs off that key, its own Embed still joined with "_".
        self::assertSame([
            'engine' => CompilerSportsEngine::class,
            'engine.boost' => 2,
            'engine.horsepower' => 500,
            'engine.piston_diameter' => 10,
        ], self::loadSorted($valueStore, CompilerOwner::class));

        // The engine's own class-level defaults are untouched by the owner's.
        self::assertSame(
            ['horsepower' => 300, 'piston_diameter' => 10],
            self::loadSorted($valueStore, CompilerOwnedEngine::class),
        );
    }

    public function testCompileTraversesCollectionItemsWithADotAndTheirIndex(): void {
        $valueStore = self::compileIntoFreshStore([
            CompilerCollectionOwner::class,
            CompilerOwnedEngine::class,
            CompilerPiston::class,
        ]);

        self::assertSame([
            'engines.0' => CompilerOwnedEngine::class,
            'engines.0.horsepower' => 400,
            'engines.0.piston_diameter' => 10,
            'pistons.0.diameter' => 10,
            'tags.0' => 'a',
            'tags.1' => 'b',
        ], self::loadSorted($valueStore, CompilerCollectionOwner::class));
    }

    public function testCompileWithAbsentOwningReferenceTargetThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/OwningReference target/');

        $this->compiler->compile(self::blueprintsOf([CompilerOwnerOfAbsentTarget::class]));
    }

    public function testCompileWithAbsentCollectionOwningReferenceItemTargetThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/OwningReference target/');

        $this->compiler->compile(self::blueprintsOf([CompilerCollectionOwnerOfAbsentTarget::class]));
    }

    public function testCompileWithAbsentReferenceTargetThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Reference or OwningReference target/');

        $this->compiler->compile(self::blueprintsOf([CompilerReferrerOfAbsentTarget::class]));
    }

    public function testCompileWithAbsentCollectionReferenceItemTargetThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Reference or OwningReference target/');

        $this->compiler->compile(self::blueprintsOf([CompilerCollectionReferrerOfAbsentTarget::class]));
    }

    public function testCompileAllowsAnOwningReferenceCycle(): void {
        $schemas = $this->compiler->compile(self::blueprintsOf([CompilerOwningCycleA::class, CompilerOwningCycleB::class]));

        self::assertCount(2, $schemas);
    }

    public function testCompileWithAnUnregisteredConcreteOwnedClassThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/holds a .*neither given to compile/');

        // CompilerOwner's default holds a CompilerSportsEngine, which isn't in
        // this batch, even though the declared target, CompilerOwnedEngine, is.
        $this->compiler->compile(self::blueprintsOf([
            CompilerOwner::class,
            CompilerOwnedEngine::class,
            CompilerPiston::class,
        ]));
    }

    public function testCompileWithAConcreteOwnedClassOutsideTheDeclaredTargetsHierarchyThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nor one of its descendants/');

        $this->compiler->compile(self::blueprintsOf([
            CompilerOwnerOfUnrelatedClass::class,
            CompilerOwnedEngine::class,
            CompilerPiston::class,
        ]));
    }

    public function testCompileThatFailsPartWayLeavesTheRegistryAndTheStoreUntouched(): void {
        $registry = new SchemaRegistry();
        $valueStore = new SchemaFieldValueStore(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $valueStore->createTable();
        $compiler = new SchemaCompiler($registry, $valueStore);

        // CompilerPiston and CompilerOwnedEngine are fine on their own; only
        // CompilerOwnerOfUnrelatedClass fails, and only while its values are
        // being computed. Listed last so it's also processed last, after the
        // other two have already been computed.
        try {
            $compiler->compile(self::blueprintsOf([
                CompilerPiston::class,
                CompilerOwnedEngine::class,
                CompilerOwnerOfUnrelatedClass::class,
            ]));
            self::fail('compile() should have thrown.');
        } catch (InvalidArgumentException) {
        }

        self::assertFalse($registry->has(CompilerPiston::class));
        self::assertFalse($registry->has(CompilerOwnedEngine::class));
        self::assertSame([], $valueStore->load(CompilerPiston::class));
        self::assertSame([], $valueStore->load(CompilerOwnedEngine::class));
    }
}
