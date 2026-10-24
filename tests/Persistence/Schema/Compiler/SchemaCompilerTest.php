<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Compiler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

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
    #[Embed]
    private CompilerEmbedTarget $target;

    public function __construct() {
        $this->target = new CompilerEmbedTarget();
    }
}

#[Entity]
final class CompilerDeepEmbedMiddle
{
    #[Embed]
    private CompilerEmbedTarget $target;

    public function __construct() {
        $this->target = new CompilerEmbedTarget();
    }
}

#[Entity]
final class CompilerDeepEmbedOuter
{
    #[Embed]
    private CompilerDeepEmbedMiddle $middle;

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
    #[Embed]
    private ?CompilerCycleB $b = null;
}

#[Entity]
final class CompilerCycleB
{
    #[Embed]
    private ?CompilerCycleA $a = null;
}

#[Entity]
final class CompilerNode
{
    #[OwningReference]
    private ?CompilerNode $child = null;
}

#[Entity]
final class CompilerPiston
{
    #[Scalar(ScalarType::Int)]
    private int $diameter;

    public function __construct(int $diameter = 10) {
        $this->diameter = $diameter;
    }
}

#[Entity]
final class CompilerWidePistonHolder
{
    #[Embed]
    private CompilerPiston $piston;

    public function __construct() {
        $this->piston = new CompilerPiston(12);
    }
}

#[Entity]
class CompilerOwnedEngine
{
    #[Scalar(ScalarType::Int)]
    private int $horsepower;

    #[Embed]
    private CompilerPiston $piston;

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
final class CompilerTunedEngine extends CompilerOwnedEngine
{
    public function __construct() {
        parent::__construct(450);
    }
}

#[Entity]
class CompilerTagged
{
    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
    protected array $tags = ['a'];
}

#[Entity]
final class CompilerSameTags extends CompilerTagged
{
}

#[Entity]
final class CompilerMoreTags extends CompilerTagged
{
    public function __construct() {
        $this->tags = ['a', 'b'];
    }
}

#[Entity]
final class CompilerOwner
{
    #[OwningReference]
    private ?CompilerOwnedEngine $engine;

    public function __construct() {
        $this->engine = new CompilerSportsEngine(500);
    }
}

#[Entity]
final class CompilerCollectionOwner
{
    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
    private array $tags = ['a', 'b'];

    #[Collection(itemKind: FieldKind::Embed, itemTarget: CompilerPiston::class)]
    private array $pistons;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: CompilerOwnedEngine::class, itemNullability: Nullability::Nullable)]
    private array $engines;

    public function __construct() {
        $this->pistons = [new CompilerPiston()];
        $this->engines = [new CompilerOwnedEngine(400)];
    }
}

#[Entity]
final class CompilerOwnerOfAbsentTarget
{
    #[OwningReference]
    private ?CompilerPiston $piston = null;
}

#[Entity]
final class CompilerCollectionOwnerOfAbsentTarget
{
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: CompilerPiston::class, itemNullability: Nullability::Nullable)]
    private array $pistons = [];
}

#[Entity]
final class CompilerReferrerOfAbsentTarget
{
    #[Reference]
    private ?CompilerPiston $piston = null;
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
    #[OwningReference]
    private ?CompilerOwningCycleB $b = null;
}

#[Entity]
final class CompilerOwningCycleB
{
    #[OwningReference]
    private ?CompilerOwningCycleA $a = null;
}

#[Entity]
final class CompilerOwnerOfUnrelatedClass
{
    /**
     * Holds nothing here: PHP's own property type wouldn't let a native class
     * hold anything but a CompilerOwnedEngine. Tests swap in an unrelated class
     * through holdingUnrelatedClass(), the way an editor-created blueprint,
     * with no property type to stop it, could carry one.
     */
    #[OwningReference]
    private ?CompilerOwnedEngine $engine = null;
}

final class CompilerMoney implements StorableValue
{
    public function __construct(
        private readonly string $amount,
    ) {
    }

    public function toStorage(): string {
        return $this->amount;
    }

    public static function indexable(): bool {
        return false;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

#[Entity]
class CompilerPriced
{
    #[ValueObject]
    protected CompilerMoney $price;

    public function __construct() {
        $this->price = new CompilerMoney('10 EUR');
    }
}

#[Entity]
final class CompilerSamePrice extends CompilerPriced
{
}

#[Entity]
final class CompilerDiscounted extends CompilerPriced
{
    public function __construct() {
        $this->price = new CompilerMoney('8 EUR');
    }
}

#[Entity]
final class CompilerBolt
{
    #[Scalar(ScalarType::Int)]
    private int $size = 1;
}

#[Entity]
final class CompilerHubWithBolt
{
    #[OwningReference]
    private ?CompilerBolt $bolt = null;
}

#[Entity]
final class CompilerHubWithBolts
{
    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::Int, itemNullability: Nullability::NotNullable)]
    private array $bolts = [];
}

#[Entity]
final class CompilerWheelEmbeddingAHub
{
    #[Embed]
    private CompilerHubWithBolts $hub;

    public function __construct() {
        $this->hub = new CompilerHubWithBolts();
    }
}

#[Entity]
final class CompilerCarWithOwningItems
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: CompilerHubWithBolt::class)]
    private array $hubs = [];
}

#[Entity]
final class CompilerCarWithCollectionItems
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: CompilerHubWithBolts::class)]
    private array $hubs = [];
}

#[Entity]
final class CompilerCarWithNestedItems
{
    #[Collection(itemKind: FieldKind::Embed, itemTarget: CompilerWheelEmbeddingAHub::class)]
    private array $wheels = [];
}

#[Entity]
final class CompilerCarWithOwnedHubs
{
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: CompilerHubWithBolts::class, itemNullability: Nullability::Nullable)]
    private array $hubs = [];
}

#[Entity]
final class CompilerCarEmbeddingOwningHub
{
    #[Embed]
    private CompilerHubWithBolt $hub;

    public function __construct() {
        $this->hub = new CompilerHubWithBolt();
    }
}

enum CompilerFuel: int
{
    case Petrol = 1;
    case Diesel = 2;
}

#[Entity]
class CompilerDatedBase
{
    #[Scalar(ScalarType::Enum)]
    private CompilerFuel $fuel = CompilerFuel::Petrol;

    #[Scalar(ScalarType::DateTime)]
    private DateTimeImmutable $since;

    public function __construct() {
        $this->since = new DateTimeImmutable('2024-01-01 12:00:00', new DateTimeZone('UTC'));
    }
}

#[Entity]
final class CompilerDatedSame extends CompilerDatedBase
{
}

/** Its items can't be null, but its default holds one: PHP can't tell. */
#[Entity]
final class CompilerNullItemDefault
{
    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::Int, itemNullability: Nullability::NotNullable)]
    private array $sizes = [17, null];
}

final class SchemaCompilerTest extends TestCase
{
    public function testCompileRejectsADefaultThatBreaksItsFieldsNullability(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default:compiler_null_item_default: its default "sizes.1" can\'t be null.');

        $this->compiler->compile(self::blueprintsOf([CompilerNullItemDefault::class]));
    }

    private SchemaCompiler $compiler;

    protected function setUp(): void {
        $this->compiler = (new InMemorySchemaStores())->compiler();
    }

    /**
     * Stands in for whatever a real caller does before handing a batch to
     * compile(): reading each native class's own blueprint. compile() itself
     * never does this.
     * @param class-string[] $classes
     * @return array<string, \XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint>
     */
    private static function blueprintsOf(array $classes): array {
        return NativeEntityReader::readAll($classes);
    }

    /**
     * $blueprints, with CompilerOwnerOfUnrelatedClass's engine holding a
     * CompilerPiston: outside its declared target's hierarchy.
     * @param array<string, EntityBlueprint> $blueprints
     * @return array<string, EntityBlueprint>
     */
    private static function holdingUnrelatedClass(array $blueprints): array {
        $original = $blueprints['default:compiler_owner_of_unrelated_class'];
        $blueprints['default:compiler_owner_of_unrelated_class'] = new EntityBlueprint(
            identifier: $original->identifier,
            source: $original->source,
            parent: $original->parent,
            table: $original->table,
            editorExtensible: $original->editorExtensible,
            fields: $original->fields,
            fieldPermissions: $original->fieldPermissions,
            fieldValidators: $original->fieldValidators,
            uniqueGroups: $original->uniqueGroups,
            prototypeValidators: $original->prototypeValidators,
            defaultValues: ObjectNode::fromArray(['engine' => new OwnedNode('default:compiler_piston', ObjectNode::fromArray(['diameter' => new LeafNode(10)]))]),
        );

        return $blueprints;
    }

    public function testCompileOrdersParentBeforeChild(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerChild::class, CompilerRoot::class])));

        self::assertSame(['default:compiler_root', 'default:compiler_child'], $order);
    }

    public function testCompileOrdersEmbedTargetBeforeEmbedder(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerEmbedder::class, CompilerEmbedTarget::class])));

        self::assertSame(['default:compiler_embed_target', 'default:compiler_embedder'], $order);
    }

    public function testCompileOrdersATransitiveEmbedChainCorrectly(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([
            CompilerDeepEmbedOuter::class,
            CompilerDeepEmbedMiddle::class,
            CompilerEmbedTarget::class,
        ])));

        self::assertSame([
            'default:compiler_embed_target',
            'default:compiler_deep_embed_middle',
            'default:compiler_deep_embed_outer',
        ], $order);
    }

    public function testCompileOrdersCollectionEmbedItemTargetBeforeEmbedder(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerCollectionEmbedder::class, CompilerEmbedTarget::class])));

        self::assertSame(['default:compiler_embed_target', 'default:compiler_collection_embedder'], $order);
    }

    public function testCompileLeavesASelfReferencingOwningReferenceAlone(): void {
        $order = array_keys($this->compiler->compile(self::blueprintsOf([CompilerNode::class])));

        self::assertSame(['default:compiler_node'], $order);
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

        self::assertSame(['default:compiler_child'], array_keys($schemas));
    }

    public function testCompileRegistersEveryCompiledSchemaWithTheRegistry(): void {
        $stores = new InMemorySchemaStores();
        $registry = $stores->registry;
        $compiler = $stores->compiler();

        $compiler->compile(self::blueprintsOf([CompilerEmbedTarget::class]));

        self::assertTrue($registry->has('default:compiler_embed_target'));
    }

    /** @param class-string[] $classes */
    private static function compileIntoFreshStore(array $classes): SchemaFieldValueStore {
        $stores = new InMemorySchemaStores();
        $stores->compiler()->compile(self::blueprintsOf($classes));

        return $stores->values;
    }

    /**
     * What $class's identifier stores, sorted by key, so assertions don't
     * depend on the order the database hands rows back in.
     * @param class-string $class
     * @return array<string, mixed>
     */
    private static function loadSorted(SchemaFieldValueStore $valueStore, string $class): array {
        $values = $valueStore->load(NativeIdentifiers::of($class))->toArray();
        ksort($values, SORT_STRING);

        return $values;
    }

    public function testCompileWritesAClassOwnScalarValues(): void {
        $valueStore = self::compileIntoFreshStore([CompilerEmbedTarget::class]);

        self::assertSame(['value' => ''], self::loadSorted($valueStore, CompilerEmbedTarget::class));
    }

    public function testCompileStoresNothingForAnEmbedEqualToItsTarget(): void {
        $valueStore = self::compileIntoFreshStore([CompilerEmbedder::class, CompilerEmbedTarget::class]);

        self::assertSame([], self::loadSorted($valueStore, CompilerEmbedder::class));
    }

    public function testCompileStoresAnEmbedFieldThatDiffersFromItsTarget(): void {
        $valueStore = self::compileIntoFreshStore([CompilerWidePistonHolder::class, CompilerPiston::class]);

        self::assertSame(['piston:diameter' => 12], self::loadSorted($valueStore, CompilerWidePistonHolder::class));
    }

    public function testCompileStoresNothingAChildInheritsUnchanged(): void {
        $valueStore = self::compileIntoFreshStore([CompilerSportsEngine::class, CompilerOwnedEngine::class, CompilerPiston::class]);

        // horsepower and piston:diameter equal CompilerOwnedEngine's own.
        self::assertSame(['boost' => 2], self::loadSorted($valueStore, CompilerSportsEngine::class));
    }

    public function testCompileStoresAnInheritedFieldTheChildChanges(): void {
        $valueStore = self::compileIntoFreshStore([CompilerTunedEngine::class, CompilerOwnedEngine::class, CompilerPiston::class]);

        self::assertSame(['horsepower' => 450], self::loadSorted($valueStore, CompilerTunedEngine::class));
    }

    public function testCompileFallsBackToWhatAnEarlierCompileStored(): void {
        $stores = new InMemorySchemaStores();
        $registry = $stores->registry;
        $valueStore = $stores->values;
        $compiler = $stores->compiler();

        $compiler->compile(self::blueprintsOf([CompilerOwnedEngine::class, CompilerPiston::class]));
        // The parent isn't in this batch: its values come from the store,
        // already sparse, and resolve through to CompilerPiston's own.
        $compiler->compile(self::blueprintsOf([CompilerSportsEngine::class, CompilerTunedEngine::class]));

        self::assertSame(['boost' => 2], self::loadSorted($valueStore, CompilerSportsEngine::class));
        self::assertSame(['horsepower' => 450], self::loadSorted($valueStore, CompilerTunedEngine::class));
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
        // declared target. Of what the owner set on it, only what differs from
        // CompilerSportsEngine's own values is kept: boost and the piston's
        // diameter match it, horsepower doesn't.
        self::assertSame([
            'engine' => 'default:compiler_sports_engine',
            'engine.horsepower' => 500,
        ], self::loadSorted($valueStore, CompilerOwner::class));

        // The engine's own defaults are untouched by the owner's. Its piston's
        // diameter matches CompilerPiston's own, so it isn't kept either.
        self::assertSame(['horsepower' => 300], self::loadSorted($valueStore, CompilerOwnedEngine::class));
    }

    public function testCompileTraversesCollectionItemsWithADotAndTheirIndex(): void {
        $valueStore = self::compileIntoFreshStore([
            CompilerCollectionOwner::class,
            CompilerOwnedEngine::class,
            CompilerPiston::class,
        ]);

        // Each bare collection path holds its item count. Nothing to fall back
        // to on a root, so every collection is kept whole; within an item,
        // only what differs from the item's own class is: engine 0's
        // horsepower, not either piston diameter.
        self::assertSame([
            'engines' => 1,
            'engines.0' => 'default:compiler_owned_engine',
            'engines.0.horsepower' => 400,
            'pistons' => 1,
            'tags' => 2,
            'tags.0' => 'a',
            'tags.1' => 'b',
        ], self::loadSorted($valueStore, CompilerCollectionOwner::class));
    }

    public function testCompileComparesAValueObjectByTheStringItStoresAs(): void {
        $stores = new InMemorySchemaStores();
        $stores->valueObjects->register(Identifier::of('test', 'money'), CompilerMoney::class);
        $registry = $stores->registry;
        $valueStore = $stores->values;
        $compiler = $stores->compiler();

        $compiler->compile(self::blueprintsOf([CompilerPriced::class]));
        // The parent's price now comes back from the store, as a string: an
        // equal value object must still compare equal to it.
        $compiler->compile(self::blueprintsOf([CompilerSamePrice::class, CompilerDiscounted::class]));

        self::assertSame(['price' => '10 EUR'], self::loadSorted($valueStore, CompilerPriced::class));
        self::assertSame([], self::loadSorted($valueStore, CompilerSamePrice::class));
        self::assertSame(['price' => '8 EUR'], self::loadSorted($valueStore, CompilerDiscounted::class));
    }

    public function testCompileStoresNothingOfACollectionAChildInheritsUnchanged(): void {
        $valueStore = self::compileIntoFreshStore([CompilerTagged::class, CompilerSameTags::class]);

        self::assertSame([], self::loadSorted($valueStore, CompilerSameTags::class));
    }

    public function testCompileStoresAChangedCollectionWhole(): void {
        $valueStore = self::compileIntoFreshStore([CompilerTagged::class, CompilerMoreTags::class]);

        // Item 0 equals the parent's item 0, but the collection differs, so
        // it's kept whole.
        self::assertSame(
            ['tags' => 2, 'tags.0' => 'a', 'tags.1' => 'b'],
            self::loadSorted($valueStore, CompilerMoreTags::class),
        );
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

        $this->compiler->compile(self::holdingUnrelatedClass(self::blueprintsOf([
            CompilerOwnerOfUnrelatedClass::class,
            CompilerOwnedEngine::class,
            CompilerPiston::class,
        ])));
    }

    public function testCompileThatFailsPartWayLeavesTheRegistryAndTheStoreUntouched(): void {
        $stores = new InMemorySchemaStores();
        $registry = $stores->registry;
        $valueStore = $stores->values;
        $compiler = $stores->compiler();

        // CompilerPiston and CompilerOwnedEngine are fine on their own; only
        // CompilerOwnerOfUnrelatedClass fails, and only while its values are
        // being computed. Listed last so it's also processed last, after the
        // other two have already been computed.
        try {
            $compiler->compile(self::holdingUnrelatedClass(self::blueprintsOf([
                CompilerPiston::class,
                CompilerOwnedEngine::class,
                CompilerOwnerOfUnrelatedClass::class,
            ])));
            self::fail('compile() should have thrown.');
        } catch (InvalidArgumentException) {
        }

        self::assertFalse($registry->has('default:compiler_piston'));
        self::assertFalse($registry->has('default:compiler_owned_engine'));
        self::assertSame([], $valueStore->load('default:compiler_piston')->toArray());
        self::assertSame([], $valueStore->load('default:compiler_owned_engine')->toArray());
    }

    /** @return array<string, array{list<class-string>, string}> */
    public static function embedItemsOwningSomething(): array {
        return [
            'an OwningReference' => [[CompilerBolt::class, CompilerHubWithBolt::class, CompilerCarWithOwningItems::class], 'the items of "hubs" hold "bolt"'],
            'a Collection' => [[CompilerHubWithBolts::class, CompilerCarWithCollectionItems::class], 'the items of "hubs" hold "bolts"'],
            'a Collection inside an Embed of the item' => [[CompilerHubWithBolts::class, CompilerWheelEmbeddingAHub::class, CompilerCarWithNestedItems::class], 'the items of "wheels" hold "hub:bolts"'],
        ];
    }

    /** @param list<class-string> $classes */
    #[DataProvider('embedItemsOwningSomething')]
    public function testCompileRejectsAnEmbedItemOwningSomething(array $classes, string $message): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->compiler->compile(self::blueprintsOf($classes));
    }

    public function testCompileAllowsOwnedItemsAndSingularEmbedsToOwnThings(): void {
        $schemas = $this->compiler->compile(self::blueprintsOf([
            CompilerBolt::class,
            CompilerHubWithBolt::class,
            CompilerHubWithBolts::class,
            CompilerCarWithOwnedHubs::class,
            CompilerCarEmbeddingOwningHub::class,
        ]));

        self::assertCount(5, $schemas);
    }

    public function testComputeWritesAndRegistersNothing(): void {
        $stores = new InMemorySchemaStores();

        $batch = $stores->compiler()->compute(self::blueprintsOf([CompilerOwnedEngine::class, CompilerPiston::class]));

        self::assertSame(['default:compiler_piston', 'default:compiler_owned_engine'], array_keys($batch->schemas));
        self::assertSame(['horsepower' => 300], $batch->values['default:compiler_owned_engine']->toArray());
        self::assertSame(SchemaSource::Native, $batch->stored[1]->source);
        self::assertFalse($stores->registry->has('default:compiler_owned_engine'));
        self::assertNull($stores->schemas->load('default:compiler_owned_engine'));
        self::assertSame([], $stores->values->load('default:compiler_owned_engine')->toArray());
    }

    public function testStoreWritesWhatComputeProduced(): void {
        $stores = new InMemorySchemaStores();
        $compiler = $stores->compiler();

        $compiler->store($compiler->compute(self::blueprintsOf([CompilerOwnedEngine::class, CompilerPiston::class])));

        self::assertNotNull($stores->schemas->load('default:compiler_owned_engine'));
        self::assertSame(['horsepower' => 300], self::loadSorted($stores->values, CompilerOwnedEngine::class));
    }

    public function testStoreJoinsATransactionAlreadyOpenAndRollsBackWithIt(): void {
        $stores = new InMemorySchemaStores();
        $compiler = $stores->compiler();
        $batch = $compiler->compute(self::blueprintsOf([CompilerOwnedEngine::class, CompilerPiston::class]));

        $stores->connection->beginTransaction();
        $compiler->store($batch);
        $stores->connection->rollBack();

        self::assertNull($stores->schemas->load('default:compiler_owned_engine'));
        self::assertSame([], $stores->values->load('default:compiler_owned_engine')->toArray());
    }

    public function testCompileStoresEnumsAndDatesInTheirStoredForm(): void {
        $stores = new InMemorySchemaStores();
        $stores->enums->register(Identifier::of('test', 'fuel'), CompilerFuel::class);
        $stores->compiler()->compile(self::blueprintsOf([CompilerDatedBase::class]));

        self::assertSame(['fuel' => 1, 'since' => '2024-01-01 12:00:00'], self::loadSorted($stores->values, CompilerDatedBase::class));
    }

    public function testASubclassWithTheSameEnumAndDateDefaultsStoresNothing(): void {
        // Compiled apart, so the parent's values come back from the store:
        // they must still compare equal to the subclass's own.
        $stores = new InMemorySchemaStores();
        $stores->enums->register(Identifier::of('test', 'fuel'), CompilerFuel::class);
        $stores->compiler()->compile(self::blueprintsOf([CompilerDatedBase::class]));
        $stores->compiler()->compile(self::blueprintsOf([CompilerDatedSame::class]));

        self::assertSame([], self::loadSorted($stores->values, CompilerDatedSame::class));
    }
}
