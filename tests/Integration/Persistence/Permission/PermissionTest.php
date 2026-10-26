<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission;

use Closure;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;
use XyloIsCoding\CoconutCms\Persistence\Query\Is;
use XyloIsCoding\CoconutCms\Persistence\Query\Item;
use XyloIsCoding\CoconutCms\Persistence\Query\Query;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** Field permissions: what a session hides, keeps, refuses and won't query. */
final class PermissionTest extends TestCase
{
    private Connection $connection;
    private Persistence $persistence;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(PermissionFixtures::class);

        $this->persistence = Persistence::boot($this->connection, [new PermissionModule()]);
        $this->persistence->migrate();
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** A new session, as a request of an actor holding $roles gets. */
    private function as(string ...$roles): PersistenceSession {
        return $this->persistence->as(new PermActor(array_values($roles)));
    }

    /** A car with every hidden field set, saved by an admin mechanic. @return string Its id. */
    private function savedCar(): string {
        $car = new PermCar();
        $car->name = 'Family car';
        $car->price = 10;
        $car->internal = 'classified';
        $car->engine = new PermEngine();
        $car->engine->horsepower = 300;
        $car->chassis->serial = 'CH-1';
        $label = new PermLabel();
        $label->text = 'red';
        $label->note = 'repainted';
        $car->labels = [$label];
        $car->codes = ['A', 'B'];

        return $this->as('admin', 'mechanic')->save($car);
    }

    private static function isSet(object $object, string $property): bool {
        return (new ReflectionProperty($object, $property))->isInitialized($object);
    }

    public function testAFieldTheActorMayNotReadLoadsUnset(): void {
        $id = $this->savedCar();

        $car = $this->as()->load($id);

        self::assertSame('Family car', $car->name);
        self::assertSame(10, $car->price);
        self::assertFalse(self::isSet($car, 'internal'));
        self::assertFalse(self::isSet($car, 'engine'));
        self::assertFalse(self::isSet($car, 'codes'));
        self::assertFalse(self::isSet($car->chassis, 'serial'));
        self::assertSame(1000, $car->chassis->weight);
        self::assertSame('red', $car->labels[0]->text);
        self::assertFalse(self::isSet($car->labels[0], 'note'));

        $mechanic = $this->as('mechanic')->load($id);
        self::assertSame('CH-1', $mechanic->chassis->serial);
        self::assertFalse(self::isSet($mechanic, 'internal'));
    }

    public function testASaveKeepsWhatTheActorCouldNotRead(): void {
        $id = $this->savedCar();
        $engineId = $this->as('admin')->load($id)->engine->id;

        $session = $this->as();
        $car = $session->load($id);
        $car->name = 'Renamed';
        $car->labels[0]->text = 'blue';
        $session->save($car);

        $loaded = $this->as('admin', 'mechanic')->load($id);
        self::assertSame('Renamed', $loaded->name);
        self::assertSame('classified', $loaded->internal);
        self::assertSame(300, $loaded->engine->horsepower);
        self::assertSame($engineId, $loaded->engine->id, 'The hidden engine is the same owned entity, not a copy.');
        self::assertSame(['A', 'B'], $loaded->codes);
        self::assertSame('CH-1', $loaded->chassis->serial);
        self::assertSame('blue', $loaded->labels[0]->text);
        self::assertSame('repainted', $loaded->labels[0]->note);
    }

    public function testChangingAFieldWithoutTheRightToWriteItIsRefused(): void {
        $id = $this->savedCar();
        $session = $this->as();
        $car = $session->load($id);
        $car->name = 'Renamed';
        $car->price = 20;

        try {
            $session->save($car);
            self::fail('Changing the price should be refused.');
        } catch (PermissionDenied $denied) {
            self::assertSame(['price'], $denied->paths);
        }

        $loaded = $this->as('admin')->load($id);
        self::assertSame(10, $loaded->price);
        self::assertSame('Family car', $loaded->name, 'Nothing of a refused save is written.');
    }

    public function testAnUnchangedFieldSavesWithoutTheRightToWriteIt(): void {
        $id = $this->savedCar();
        $session = $this->as();
        $car = $session->load($id);
        $car->name = 'Renamed';

        $session->save($car);

        self::assertSame('Renamed', $this->as('admin')->load($id)->name);
    }

    public function testANewEntityIsComparedWithItsDefaults(): void {
        $plain = new PermCar();
        $plain->name = 'Plain';
        self::assertNotNull($this->as()->save($plain));

        $priced = new PermCar();
        $priced->price = 5;
        $priced->internal = 'mine';
        $this->expectException(PermissionDenied::class);
        $this->expectExceptionMessageMatches('/price.*internal|internal.*price/');
        $this->as()->save($priced);
    }

    public function testValidatorsSeeWhatTheActorCannot(): void {
        $car = new PermCar();
        $car->name = 'Secret';

        $this->expectException(ValidationFailed::class);
        $this->as()->save($car);
    }

    /** @return array<string, array{Closure(Query): Query}> */
    public static function unreadablePaths(): array {
        return [
            'a condition' => [static fn (Query $query): Query => $query->where('internal', Is::equalTo('classified'))],
            'a sort' => [static fn (Query $query): Query => $query->orderBy('internal')],
            'inside an Embed' => [static fn (Query $query): Query => $query->where('chassis:serial', Is::equalTo('CH-1'))],
            'a step through an owned object' => [static fn (Query $query): Query => $query->where('engine.horsepower', Is::greaterThan(200))],
            'a Collection' => [static fn (Query $query): Query => $query->whereHas('codes')],
            'a count' => [static fn (Query $query): Query => $query->whereCount('codes', Is::greaterThan(1))],
            'inside an item' => [static fn (Query $query): Query => $query->whereHas('labels', Item::where('note', Is::equalTo('repainted')))],
        ];
    }

    /** @param Closure(Query): Query $condition */
    #[DataProvider('unreadablePaths')]
    public function testAQueryThroughAFieldTheActorMayNotReadIsRefused(Closure $condition): void {
        $this->savedCar();

        self::assertCount(1, $condition($this->as('admin', 'mechanic')->query(PermCar::class))->ids());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no field/i');
        $condition($this->as()->query(PermCar::class))->ids();
    }

    public function testAReadonlyPropertyCannotHaveAReadPermission(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is readonly and has a read permission');
        NativeEntityReader::read(PermReadonlySecret::class);
    }
}
