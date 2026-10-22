<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Entity\Native;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityTables;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityLoader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsCar;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsEngine;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsFixtures;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsMaker;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsTurbo;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsWheel;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

final class NativeEntityLoaderTest extends TestCase
{
    private Connection $connection;
    private InMemorySchemaStores $stores;
    private NativeEntityRegistry $classes;
    /** @var list<LevelLayout> */
    private array $levels = [];

    protected function setUp(): void {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'user' => 'root',
            'password' => 'root',
            'unix_socket' => '/Users/davidebosco/Library/Application Support/Local/run/PzVL9Ueo_/mysql/mysqld.sock',
            'dbname' => 'local',
        ]);

        $classes = [Cms::class, ...RowsFixtures::CLASSES];
        $blueprints = NativeEntityReader::readAll($classes);
        $this->stores = new InMemorySchemaStores();
        $this->stores->compiler()->compile($blueprints);

        $layouts = new LayoutBuilder(new SchemaLookup($this->stores->registry));
        foreach (array_keys($blueprints) as $identifier) {
            $this->levels[] = $layouts->levelOf($identifier);
        }

        $this->classes = new NativeEntityRegistry();
        foreach ($classes as $class) {
            $this->classes->register($class);
        }

        $this->dropTables();
        (new EntityTables($this->connection))->create($this->levels);
    }

    protected function tearDown(): void {
        $this->dropTables();
        $this->connection->close();
    }

    private function dropTables(): void {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (DatabaseSchema::of($this->levels)->getTables() as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table->getName()));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function save(object $entity, IdentityMap $identities): string {
        $writer = new EntityWriter($this->connection, $this->stores->registry);

        return (new NativeEntityWriter($writer, $this->stores->registry, $this->stores->values, $identities))->save($entity);
    }

    /** A loader with nothing in memory yet: what a fresh request starts with. */
    private function freshLoader(): NativeEntityLoader {
        return $this->loaderWith(new IdentityMap());
    }

    private function loaderWith(IdentityMap $identities): NativeEntityLoader {
        return new NativeEntityLoader(new EntityReader($this->connection, $this->stores->registry, $this->stores->values), $this->classes, $identities);
    }

    private function fullCar(IdentityMap $identities): RowsCar {
        $maker = new RowsMaker();
        $maker->name = 'acme';
        $this->save($maker, $identities);

        $car = new RowsCar();
        $car->name = 'mine';
        $car->chassis->weight = 1200;
        $car->engine->horsepower = 450;
        $car->engine->turbo = new RowsTurbo();
        $car->engine->turbo->boost = 5;
        $car->spares = [new RowsEngine(), null];
        $car->wheels = [new RowsWheel(), new RowsWheel()];
        $car->wheels[1]->size = 19;
        $car->wheels[1]->maker = $maker;
        $car->tags = ['red', 'fast'];
        $car->maker = $maker;

        return $car;
    }

    public function testALoadedEntityEqualsTheOneSaved(): void {
        $identities = new IdentityMap();
        $car = $this->fullCar($identities);
        $id = $this->save($car, $identities);

        $loaded = $this->freshLoader()->load($id);
        // PHPUnit compares raw properties without initializing a lazy ghost:
        // initialize the maker the way any real read of it would.
        (new ReflectionClass(RowsMaker::class))->initializeLazyObject($loaded->maker);

        self::assertNotSame($car, $loaded);
        self::assertEquals($car, $loaded);
    }

    public function testAnUntouchedValueFollowsItsClassDefaultWhenItChanges(): void {
        $identities = new IdentityMap();
        $car = new RowsCar();
        $car->name = 'mine';
        $id = $this->save($car, $identities);

        // RowsChassis's default weight changes after the car was saved.
        $this->stores->values->save('default:rows_chassis', FieldValues::fromArray(['weight' => 2000]));
        $loaded = $this->freshLoader()->load($id);

        self::assertSame('mine', $loaded->name);
        self::assertSame(2000, $loaded->chassis->weight);
    }

    public function testAnOwnedObjectNeverTouchedIsBuiltFromItsDefaultWithNoId(): void {
        $identities = new IdentityMap();
        $id = $this->save(new RowsCar(), $identities);
        $loaded = new IdentityMap();
        $loader = $this->loaderWith($loaded);

        $car = $loader->load($id);

        self::assertEquals(new RowsEngine(), $car->engine);
        self::assertNull($loaded->idOf($car->engine));
    }

    public function testAReferenceLoadsAsAGhostUntilFirstRead(): void {
        $identities = new IdentityMap();
        $id = $this->save($this->fullCar($identities), $identities);

        $car = $this->freshLoader()->load($id);
        $reflection = new ReflectionClass(RowsMaker::class);

        self::assertTrue($reflection->isUninitializedLazyObject($car->maker));
        self::assertSame('acme', $car->maker->name);
        self::assertFalse($reflection->isUninitializedLazyObject($car->maker));
    }

    public function testOneObjectPerId(): void {
        $identities = new IdentityMap();
        $id = $this->save($this->fullCar($identities), $identities);
        $loader = $this->freshLoader();

        $car = $loader->load($id);

        self::assertSame($car, $loader->load($id));
        self::assertSame($car->maker, $car->wheels[1]->maker);
    }

    public function testLoadingAnIdNeverSavedGivesNull(): void {
        self::assertNull($this->freshLoader()->load(EntityIds::generate()));
    }
}
