<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Entity\Native;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityTables;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityLoader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsCar;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsEngine;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsFixtures;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsMaker;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsTurbo;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsWheel;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

final class NativeEntityWriterTest extends TestCase
{
    private Connection $connection;
    private InMemorySchemaStores $stores;
    private NativeEntityRegistry $classes;
    /** @var list<LevelLayout> */
    private array $levels = [];
    private IdentityMap $identities;
    private NativeEntityWriter $writer;

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

        $this->identities = new IdentityMap();
        $this->writer = new NativeEntityWriter(new EntityWriter($this->connection, $this->stores->registry), $this->stores->registry, $this->stores->values, $this->identities);
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

    /** A loader with nothing in memory yet: what a fresh request starts with. */
    private function freshLoad(string $id): ?object {
        return (new NativeEntityLoader(new EntityReader($this->connection, $this->stores->registry, $this->stores->values), $this->classes, new IdentityMap()))->load($id);
    }

    private function exists(string $id): bool {
        return $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', DatabaseSchema::ENTITIES), [$id]) > 0;
    }

    private function countIn(string $table): int {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    public function testASavedEntityLoadsBackEqual(): void {
        $maker = new RowsMaker();
        $maker->name = 'acme';
        $this->writer->save($maker);
        $car = new RowsCar();
        $car->name = 'mine';
        $car->engine->horsepower = 450;
        $car->spares = [new RowsEngine()];
        $car->tags = ['red'];
        $car->maker = $maker;

        $loaded = $this->freshLoad($this->writer->save($car));

        self::assertSame('mine', $loaded->name);
        self::assertSame(450, $loaded->engine->horsepower);
        self::assertCount(1, $loaded->spares);
        self::assertSame(['red'], $loaded->tags);
        self::assertSame('acme', $loaded->maker->name);
    }

    public function testEverySavedObjectIsRememberedWithItsIdAssigned(): void {
        $car = new RowsCar();
        $car->engine->horsepower = 450;
        $id = $this->writer->save($car);

        self::assertSame($id, $this->identities->idOf($car));
        self::assertNotNull($car->engine->id);
        self::assertSame($car->engine->id, $this->identities->idOf($car->engine));
        self::assertTrue($this->exists($car->engine->id));
    }

    public function testSavingAgainUpdatesInPlace(): void {
        $car = new RowsCar();
        $car->engine->horsepower = 450;
        $id = $this->writer->save($car);
        $engineId = $car->engine->id;

        $car->name = 'renamed';
        $car->engine->horsepower = 500;
        $car->tags = ['red', 'fast'];
        self::assertSame($id, $this->writer->save($car));
        $car->tags = ['blue'];
        $this->writer->save($car);

        $loaded = $this->freshLoad($id);
        self::assertSame('renamed', $loaded->name);
        self::assertSame(500, $loaded->engine->horsepower);
        self::assertSame(['blue'], $loaded->tags);
        self::assertSame($engineId, $car->engine->id);
        self::assertSame(3, $this->countIn(DatabaseSchema::ENTITIES), 'Cms, the car and its engine.');
    }

    public function testAnOwnedObjectReplacedIsDeletedWithEverythingItOwns(): void {
        $car = new RowsCar();
        $car->engine->turbo = new RowsTurbo();
        $car->engine->turbo->boost = 5;
        $this->writer->save($car);
        $old = $car->engine;
        $oldTurboId = $this->identities->idOf($old->turbo);

        $car->engine = new RowsEngine();
        $car->engine->horsepower = 500;
        $this->writer->save($car);

        self::assertFalse($this->exists($oldTurboId));
        self::assertNull($this->identities->idOf($old->turbo));
        self::assertNull($old->id);
        self::assertNotNull($car->engine->id);
        self::assertTrue($this->exists($car->engine->id));
    }

    public function testAnOwnedObjectMovedToAnotherOwnerKeepsItsId(): void {
        $car = new RowsCar();
        $car->engine->turbo = new RowsTurbo();
        $car->engine->turbo->boost = 5;
        $id = $this->writer->save($car);
        $turbo = $car->engine->turbo;
        $turboId = $this->identities->idOf($turbo);

        // Its old owner is deleted in the same save that moves it.
        $car->spares = [new RowsEngine()];
        $car->spares[0]->turbo = $turbo;
        $car->engine = null;
        $this->writer->save($car);

        self::assertSame($turboId, $this->identities->idOf($turbo));
        self::assertSame(5, $this->freshLoad($id)->spares[0]->turbo->boost);
    }

    public function testAnOwnedObjectBackAtItsDefaultsLosesItsRows(): void {
        $car = new RowsCar();
        $car->engine->horsepower = 450;
        $this->writer->save($car);
        $engineId = $car->engine->id;

        $car->engine->horsepower = 300;
        $id = $this->writer->save($car);

        self::assertFalse($this->exists($engineId));
        self::assertNull($car->engine->id);
        self::assertEquals(new RowsEngine(), $this->freshLoad($id)->engine);
    }

    public function testAnItemDroppedFromAnOwnedCollectionIsDeleted(): void {
        $car = new RowsCar();
        $car->spares = [new RowsEngine(), new RowsEngine()];
        $id = $this->writer->save($car);
        $droppedId = $car->spares[1]->id;

        $car->spares = [$car->spares[0]];
        $this->writer->save($car);

        self::assertFalse($this->exists($droppedId));
        self::assertCount(1, $this->freshLoad($id)->spares);
    }

    public function testDeletingRemovesTheWholeAggregate(): void {
        $car = new RowsCar();
        $car->engine->turbo = new RowsTurbo();
        $car->wheels = [new RowsWheel()];
        $car->tags = ['red'];
        $id = $this->writer->save($car);

        $this->writer->delete($car);

        self::assertSame(1, $this->countIn(DatabaseSchema::ENTITIES), 'Only Cms is left.');
        self::assertSame(0, $this->countIn('rows_car'));
        self::assertSame(0, $this->countIn('rows_car__tags'));
        self::assertSame(0, $this->countIn('rows_car__wheels'));
        self::assertNull($this->identities->idOf($car));
        self::assertNull($car->engine->id);
        self::assertNull($this->freshLoad($id));
    }

    public function testAnOwnedEntityIsNeverSavedOnItsOwn(): void {
        $car = new RowsCar();
        $car->engine->horsepower = 450;
        $this->writer->save($car);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('through its owner');

        $this->writer->save($car->engine);
    }

    public function testAnOwnedEntityIsNeverDeletedOnItsOwn(): void {
        $car = new RowsCar();
        $car->engine->horsepower = 450;
        $this->writer->save($car);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('through its owner');

        $this->writer->delete($car->engine);
    }

    public function testAnOwnedObjectStillStoredInAnotherAggregateIsRejected(): void {
        $first = new RowsCar();
        $first->engine->horsepower = 450;
        $this->writer->save($first);

        $second = new RowsCar();
        $second->engine = $first->engine;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already stored in another aggregate');

        $this->writer->save($second);
    }

    public function testAnOwnedObjectSavedOnItsOwnBeforeItsOwnerIsRejectedWithIt(): void {
        $car = new RowsCar();
        $car->engine->horsepower = 450;
        $this->writer->save($car->engine);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already stored in another aggregate');

        $this->writer->save($car);
    }

    public function testASaveWaitsForAnotherHoldingItsAggregate(): void {
        $car = new RowsCar();
        $id = $this->writer->save($car);

        $other = DriverManager::getConnection($this->connection->getParams());
        $other->beginTransaction();
        $other->fetchOne(sprintf('SELECT id FROM %s WHERE id = ? FOR UPDATE', DatabaseSchema::ENTITIES), [$id]);
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $car->name = 'blocked';
            $this->expectException(LockWaitTimeoutException::class);
            $this->writer->save($car);
        } finally {
            $other->rollBack();
            $other->close();
        }
    }

    public function testAFailedSaveChangesNothingStoredNorInMemory(): void {
        $maker = new RowsMaker();
        $this->writer->save($maker);
        $car = new RowsCar();
        $car->name = 'before';
        $id = $this->writer->save($car);

        // The maker vanishes behind the writer's back: pointing at it fails.
        $this->connection->delete(DatabaseSchema::ENTITIES, ['id' => $this->identities->idOf($maker)]);
        $car->name = 'after';
        $car->engine->horsepower = 450;
        $car->maker = $maker;

        try {
            $this->writer->save($car);
            self::fail('The save should have failed.');
        } catch (ForeignKeyConstraintViolationException) {
        }

        self::assertSame('before', $this->freshLoad($id)->name);
        self::assertNull($car->engine->id);
        self::assertNull($this->identities->idOf($car->engine));
    }
}
