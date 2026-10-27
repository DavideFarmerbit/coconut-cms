<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Compiler;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\BlueprintDefaults;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\StoredBlueprintReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

#[Entity(identifier: 'stored:engine')]
class StoredEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;
}

#[Entity(identifier: 'stored:turbo')]
final class StoredTurbo extends StoredEngine
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 5;
}

#[Entity(identifier: 'stored:chassis')]
final class StoredChassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1000;

    #[Scalar(ScalarType::String)]
    public string $code = 'C';
}

/** Every kind of default: a site override inside an Embed, an owned subtype, each kind of Collection. */
#[Entity(identifier: 'stored:vehicle')]
class StoredVehicle
{
    #[Scalar(ScalarType::String)]
    public string $name = 'vehicle';

    #[Scalar(ScalarType::Int)]
    public int $wheels = 4;

    #[Embed]
    public StoredChassis $chassis;

    #[OwningReference]
    public ?StoredEngine $engine;

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
    public array $tags = ['a', 'b'];

    #[Collection(itemKind: FieldKind::Embed, itemTarget: StoredChassis::class)]
    public array $spareChassis;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: StoredEngine::class, itemNullability: Nullability::Nullable)]
    public array $spares;

    public function __construct() {
        $this->chassis = new StoredChassis();
        $this->chassis->weight = 1200;
        $this->engine = new StoredTurbo();
        $this->engine->horsepower = 250;
        $this->spareChassis = [new StoredChassis()];
        $this->spares = [new StoredEngine(), null];
    }
}

/** Sets some of what it inherits, and leaves the rest following its parent. */
#[Entity(identifier: 'stored:car')]
final class StoredCar extends StoredVehicle
{
    public function __construct() {
        parent::__construct();
        $this->name = 'car';
        $this->tags = ['c'];
    }
}

/** A blueprint rebuilt from what's stored compiles to what its class does, and follows its ancestors as compiled now. */
final class StoredBlueprintReaderTest extends TestCase
{
    private const CLASSES = [StoredEngine::class, StoredTurbo::class, StoredChassis::class, StoredVehicle::class, StoredCar::class];

    private InMemorySchemaStores $stores;
    private StoredBlueprintReader $reader;

    protected function setUp(): void {
        $this->stores = new InMemorySchemaStores();
        $this->stores->compiler()->compile(NativeEntityReader::readAll(self::CLASSES));
        $this->reader = new StoredBlueprintReader($this->stores->schemas, $this->stores->values);
    }

    public function testRebuiltFromWhatsStoredItCompilesToWhatItsClassDoes(): void {
        $fromClasses = $this->stores->compiler()->compute(NativeEntityReader::readAll(self::CLASSES));
        $fromStored = $this->stores->compiler()->compute($this->reader->readAll(SchemaSource::Native));

        self::assertEquals(self::keyed($fromClasses), self::keyed($fromStored));
    }

    public function testItCarriesWhatTheTypeSetsItselfOnly(): void {
        $own = $this->reader->read('stored:car')->defaults->own->toArray();

        self::assertSame('car', $own['name']);
        self::assertArrayNotHasKey('wheels', $own, 'Inherited and never set: it follows the parent.');
    }

    public function testWhatItInheritsFollowsANativeParentCompiledInTheSameBatch(): void {
        $vehicle = self::withDefault(NativeEntityReader::read(StoredVehicle::class), 'wheels', 6);
        $vehicle = self::withDefault($vehicle, 'name', 'vehicle 2');

        $this->stores->compiler()->compile(['stored:vehicle' => $vehicle, 'stored:car' => $this->reader->read('stored:car')]);

        self::assertSame(6, $this->resolve('stored:car', 'wheels'));
        self::assertSame('car', $this->resolve('stored:car', 'name'), 'What it sets itself stays.');
        self::assertSame('vehicle 2', $this->resolve('stored:vehicle', 'name'));
    }

    public function testWhatItInheritsFollowsAStoredParentChangedInTheSameBatch(): void {
        $vehicle = $this->reader->read('stored:vehicle');
        $own = FieldValues::fromArray(['wheels' => 6] + $vehicle->defaults->own->toArray());

        $this->stores->compiler()->compile([
            'stored:vehicle' => StoredBlueprintReader::of($this->stores->schemas->load('stored:vehicle')->schema, SchemaSource::Editor, $own),
            'stored:car' => $this->reader->read('stored:car'),
        ]);

        self::assertSame(6, $this->resolve('stored:car', 'wheels'));
        self::assertSame(['c'], [$this->resolve('stored:car', 'tags.0')], 'Its own Collection stays its own.');
    }

    public function testRebuiltFromStoredDefaultsThatFrozeTheParentItWouldStopFollowingIt(): void {
        // What a blueprint resolved from the stored state before the batch
        // would carry: the parent's old value, as if the car set it itself.
        $frozen = new FieldValueResolver($this->stores->registry, $this->stores->values);
        $vehicle = self::withDefault(NativeEntityReader::read(StoredVehicle::class), 'wheels', 6);
        $car = $this->reader->read('stored:car');
        $car = new EntityBlueprint(
            identifier: $car->identifier,
            source: $car->source,
            parent: $car->parent,
            table: $car->table,
            editorExtensible: $car->editorExtensible,
            fields: $car->fields,
            fieldPermissions: $car->fieldPermissions,
            fieldValidators: $car->fieldValidators,
            uniqueGroups: $car->uniqueGroups,
            prototypeValidators: $car->prototypeValidators,
            defaults: BlueprintDefaults::dense($frozen->resolveTree(Subject::of('stored:car'))),
        );

        $this->stores->compiler()->compile(['stored:vehicle' => $vehicle, 'stored:car' => $car]);

        self::assertSame(4, $this->resolve('stored:car', 'wheels'), 'Why a stored type carries only what it sets itself.');
    }

    /** $blueprint with field $name's dense default set to $value. */
    private static function withDefault(EntityBlueprint $blueprint, string $name, mixed $value): EntityBlueprint {
        return new EntityBlueprint(
            identifier: $blueprint->identifier,
            source: $blueprint->source,
            parent: $blueprint->parent,
            table: $blueprint->table,
            editorExtensible: $blueprint->editorExtensible,
            fields: $blueprint->fields,
            fieldPermissions: $blueprint->fieldPermissions,
            fieldValidators: $blueprint->fieldValidators,
            uniqueGroups: $blueprint->uniqueGroups,
            prototypeValidators: $blueprint->prototypeValidators,
            defaults: BlueprintDefaults::dense($blueprint->defaults->dense->with(FieldName::of($name), new LeafNode($value))),
        );
    }

    /** What $identifier's default resolves to at $path, from what's stored now. */
    private function resolve(string $identifier, string $path): mixed {
        return (new FieldValueResolver($this->stores->registry, $this->stores->values))->resolve(Subject::of($identifier), FieldPath::fromString($path));
    }

    /**
     * $batch keyed by identifier throughout, so two batches compare whatever
     * order their dependency sort chose among equals.
     * @return array{schemas: array<string, mixed>, values: array<string, array<string, mixed>>, stored: array<string, mixed>}
     */
    private static function keyed(CompiledBatch $batch): array {
        $schemas = $batch->schemas;
        ksort($schemas);
        $values = array_map(static function (FieldValues $values): array {
            $array = $values->toArray();
            ksort($array);

            return $array;
        }, $batch->values);
        ksort($values);
        $stored = [];
        foreach ($batch->stored as $each) {
            $stored[$each->schema->identifier] = [$each->source, $each->fingerprint, $each->dependencies];
        }
        ksort($stored);

        return ['schemas' => $schemas, 'values' => $values, 'stored' => $stored];
    }
}
