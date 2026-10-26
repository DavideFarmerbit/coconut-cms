<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Reference;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;
use XyloIsCoding\CoconutCms\Persistence\Query\Is;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermActor;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermissionFixtures;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** A Reference to an owned object, of another aggregate or its own: it's a Reference, so it reaches the object as itself. */
final class ReferenceIntoAggregateTest extends TestCase
{
    private Connection $connection;
    private Persistence $persistence;
    private string $carId;
    private string $garageId;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture files hold several classes: load them by their own.
        class_exists(ReferenceFixtures::class);
        class_exists(PermissionFixtures::class);

        $this->persistence = Persistence::boot($this->connection, [new ReferenceModule()]);
        $this->persistence->migrate();

        $session = $this->system();
        $car = new RefCar();
        $car->spares = [new RefEngine(), new RefEngine()];
        $car->spares[0]->horsepower = 300;
        $car->spares[0]->boost = 7;
        $this->carId = $session->save($car);
        $garage = new RefGarage();
        $garage->engine = $car->spares[0];
        $this->garageId = $session->save($garage);
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    private function system(): PersistenceSession {
        return $this->persistence->as(new SystemActor());
    }

    private function as(string ...$roles): PersistenceSession {
        return $this->persistence->as(new PermActor(array_values($roles)));
    }

    public function testOneEntityIsOneObjectWhicheverLoadsFirst(): void {
        $session = $this->system();
        $garage = $session->load($this->garageId);
        $car = $session->load($this->carId);
        self::assertSame($car->spares[0], $garage->engine, 'The Reference first, then its owner.');
        self::assertSame(300, $garage->engine->horsepower);

        $session = $this->system();
        $car = $session->load($this->carId);
        $garage = $session->load($this->garageId);
        self::assertSame($car->spares[0], $garage->engine, 'The owner first, then the Reference.');
    }

    public function testAReferenceToItsOwnOwnedObjectDeclaredBeforeItLoads(): void {
        $session = $this->system();
        $car = $session->load($this->carId);
        $car->favorite = $car->spares[1];
        $session->save($car);

        $loaded = $this->system()->load($this->carId);

        self::assertSame($loaded->spares[1], $loaded->favorite);
    }

    public function testAnObjectInUseIsKeptWhenItsOwnerLoads(): void {
        $session = $this->system();
        $garage = $session->load($this->garageId);
        $garage->engine->horsepower = 999;

        $car = $session->load($this->carId);

        self::assertSame($garage->engine, $car->spares[0]);
        self::assertSame(999, $car->spares[0]->horsepower, 'What the session changed stays, as loading a known entity gives it back.');
    }

    public function testLoadTakesAStandaloneIdOnly(): void {
        $engineId = $this->system()->load($this->carId)->spares[0]->id;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is owned by another entity');
        $this->system()->load($engineId);
    }

    public function testTheTargetsOwnTypeDecidesThroughAReference(): void {
        $garage = $this->as()->load($this->garageId);
        try {
            $garage->engine->horsepower;
            self::fail('Its own type is closed to this actor.');
        } catch (PermissionDenied $denied) {
            self::assertSame('ref:engine', $denied->identifier);
        }
        self::assertSame(300, $this->as()->load($this->carId)->spares[0]->horsepower, 'Through its owner, the owner decides.');

        $engine = $this->as('mechanic')->load($this->garageId)->engine;
        self::assertSame(300, $engine->horsepower);
        self::assertFalse((new ReflectionProperty($engine, 'boost'))->isInitialized($engine), 'Its own fields\' permissions still apply.');
    }

    public function testAReferenceReachesWhatItsOwnerHides(): void {
        $session = $this->system();
        $car = $session->load($this->carId);
        $car->secretEngine = new RefEngine();
        $car->secretEngine->horsepower = 900;
        $session->save($car);
        $garage = $session->load($this->garageId);
        $garage->engine = $car->secretEngine;
        $session->save($garage);

        self::assertFalse((new ReflectionProperty(RefCar::class, 'secretEngine'))->isInitialized($this->as('mechanic')->load($this->carId)));
        self::assertSame(900, $this->as('mechanic')->load($this->garageId)->engine->horsepower, 'An accessor: whoever linked it chose to.');
    }

    public function testReachedThroughItsOwnerAGhostBecomesReadable(): void {
        $session = $this->as();
        $garage = $session->load($this->garageId);
        $car = $session->load($this->carId);

        self::assertSame($car->spares[0], $garage->engine);
        self::assertSame(300, $garage->engine->horsepower, 'One object: what the owner showed, the Reference shows too.');
    }

    public function testTheOwnerReplacingItsTargetLeavesTheReferenceNull(): void {
        $session = $this->system();
        $car = $session->load($this->carId);
        $car->spares[0] = new RefEngine();
        $session->save($car);

        self::assertNull($this->system()->load($this->garageId)->engine);
    }

    public function testAQueryPastTheReferenceFollowsTheTargetsOwnType(): void {
        self::assertSame(1, $this->as('mechanic')->query(RefGarage::class)->where('engine.horsepower', Is::equalTo(300))->count());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('may not read a ref:engine');
        $this->as()->query(RefGarage::class)->where('engine.horsepower', Is::equalTo(300))->count();
    }
}
