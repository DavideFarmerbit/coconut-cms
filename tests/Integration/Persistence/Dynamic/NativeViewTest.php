<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic;

use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityLoader;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeViewClasses;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigratorEditorTypes;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** An editor-created entity loads in native code as its last native class: its native view. */
final class NativeViewTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture files hold several classes: load them by their own.
        class_exists(DynamicFixtures::class);
        class_exists(MigratorEditorTypes::class);

        Persistence::boot($this->connection, [new DynamicModule()])->migrate();
        [$schemas, $values, $registry] = $this->stores();
        MigratorEditorTypes::create($this->connection, $schemas, $values, $registry, DynamicFixtures::editorTypes());
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** @return array{SchemaStore, SchemaFieldValueStore, SchemaRegistry} What a new request starts with. */
    private function stores(): array {
        $codec = new EntitySchemaCodec(new FieldValidatorRegistry(), new PrototypeValidatorRegistry(), new ValueObjectRegistry(), new EnumRegistry());
        $schemas = new SchemaStore($this->connection, $codec);

        return [$schemas, new SchemaFieldValueStore($this->connection), new SchemaRegistry($schemas)];
    }

    /** A session of a new request, as the system. */
    private function session(): PersistenceSession {
        return Persistence::boot($this->connection, [new DynamicModule()])->as(new SystemActor());
    }

    /**
     * Stores a $type entity holding $set, every other value its default, the
     * way a save writes values.
     * @param array<string, mixed> $set Keyed by path, in stored form.
     */
    private function store(string $type, array $set): string {
        [, $values, $registry] = $this->stores();

        return MigratorEditorTypes::save($this->connection, $values, $registry, $type, $set);
    }

    /** A craft with an outboard motor: an editor-created entity in a native property. */
    private function craftWithOutboard(): string {
        return $this->store('dy:craft', ['name' => 'Tender', 'motor' => 'dy:outboard', 'motor.horsepower' => 300, 'motor.propellers' => 3, 'motor.fuel' => 'diesel']);
    }

    public function testAnEditorCreatedEntityLoadsAsItsLastNativeClassWithItsOwnDefaults(): void {
        $id = $this->store('dy:yacht', []);

        $yacht = $this->session()->load($id);

        self::assertSame(DyCraft::class, $yacht::class);
        self::assertSame('Yacht', $yacht->name, 'The yacht type\'s own default for a native field.');
    }

    public function testAnEditorCreatedOwnedObjectLoadsAsItsLastNativeClass(): void {
        $craft = $this->session()->load($this->craftWithOutboard());

        self::assertSame(DyBoatMotor::class, $craft->motor::class, 'Safe to treat as a boat motor: it is one.');
        self::assertSame(300, $craft->motor->horsepower);
        self::assertSame(3, $craft->motor->propellers);
    }

    public function testAnOverriddenNativeDefaultReachesTheView(): void {
        $id = $this->store('dy:craft', ['motor' => 'dy:outboard', 'motor.horsepower' => 300, 'motor.propellers' => 2, 'motor.fuel' => 'petrol']);

        $craft = $this->session()->load($id);

        self::assertSame(300, $craft->motor->horsepower, 'The outboard sets the native horsepower to 300, not the motor\'s 100.');
    }

    public function testAReferenceToAnEditorCreatedEntityHoldsItsNativeView(): void {
        $yachtId = $this->store('dy:yacht', ['name' => 'Seabird']);
        $id = $this->store('dy:craft', ['name' => 'Dinghy', 'tow' => $yachtId]);

        $craft = $this->session()->load($id);

        self::assertInstanceOf(DyCraft::class, $craft->tow);
        self::assertSame('Seabird', $craft->tow->name);
    }

    public function testAQueryFindsEditorCreatedEntitiesAsTheirNativeView(): void {
        $this->store('dy:yacht', ['name' => 'Seabird']);
        $this->store('dy:craft', ['name' => 'Dinghy']);

        $names = array_map(static fn (DyCraft $craft): string => $craft->name, $this->session()->query(DyCraft::class)->orderBy('name')->limit(10)->page()->items);

        self::assertSame(['Dinghy', 'Seabird'], $names);
    }

    public function testTheIdentityMapKeepsEachEntitysRealType(): void {
        $id = $this->craftWithOutboard();
        [, $values, $registry] = $this->stores();
        $classes = new NativeEntityRegistry();
        foreach (DynamicFixtures::CLASSES as $class) {
            $classes->register($class);
        }
        $identities = new IdentityMap();

        $craft = (new NativeEntityLoader(new EntityReader($this->connection, $registry, $values), new NativeViewClasses($classes, new SchemaLookup($registry)), $identities))->load($id);

        self::assertSame('dy:craft', $identities->typeOf($craft));
        self::assertSame('dy:outboard', $identities->typeOf($craft->motor));
    }

    public function testAnEntityWithNoNativeAncestorCannotLoadAsAnObject(): void {
        $id = $this->store('dy:buoy', []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('loadDynamic()');
        $this->session()->load($id);
    }

    public function testSavingAViewOfAnEditorCreatedEntityIsRefusedUntilItKeepsWhatItCannotSee(): void {
        $session = $this->session();
        $yacht = $session->load($this->store('dy:yacht', []));
        $yacht->name = 'Renamed';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('native view of a dy:yacht');
        $session->save($yacht);
    }

    public function testSavingANativeEntityHoldingAViewIsRefusedToo(): void {
        $session = $this->session();
        $craft = $session->load($this->craftWithOutboard());
        $craft->name = 'Renamed';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('native view of a dy:outboard');
        $session->save($craft);
    }
}
