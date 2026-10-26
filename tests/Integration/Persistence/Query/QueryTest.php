<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Query;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\MigrationPending;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\Query\Is;
use XyloIsCoding\CoconutCms\Persistence\Query\Order;
use XyloIsCoding\CoconutCms\Persistence\Query\Query;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

final class QueryTest extends TestCase
{
    private Connection $connection;
    private Persistence $persistence;
    private QueryMaker $acme;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(QueryFixtures::class);

        $this->persistence = Persistence::boot($this->connection, [new QueryModule()]);
        $this->persistence->migrate();

        $italy = new QueryCountry();
        $italy->name = 'Italy';
        $this->persistence->save($italy);
        $this->acme = $this->maker('Acme', $italy);
        $zenith = $this->maker('Zenith', null);

        $alpha = new QueryCar();
        $alpha->name = 'Alpha';
        $alpha->price = 100;
        $alpha->nickname = 'al';
        $alpha->chassis->weight = 1200;
        $alpha->engine = new QueryEngine();
        $alpha->engine->horsepower = 300;
        $alpha->engine->turbo = new QueryTurbo();
        $alpha->engine->turbo->boost = 5;
        $alpha->maker = $this->acme;
        $alpha->doors = 2;

        $bravo = new QueryCar();
        $bravo->name = 'Bravo';
        $bravo->price = 200;
        $bravo->fuel = QueryFuel::Diesel;
        $bravo->chassis->weight = 900;
        $bravo->maker = $zenith;

        $charlie = new QueryTruck();
        $charlie->name = 'Charlie';
        $charlie->price = 200;
        $charlie->nickname = 'ch';
        $charlie->sold = true;
        $charlie->fuel = QueryFuel::Diesel;
        $charlie->chassis->weight = 3000;
        $charlie->engine = new QueryEngine();
        $charlie->engine->horsepower = 500;
        $charlie->maker = $this->acme;

        $delta = new QueryVehicle();
        $delta->name = 'Delta';
        $delta->price = 50;
        $delta->fuel = QueryFuel::Electric;

        $echo = new QueryCar();
        $echo->name = 'Echo';
        $echo->price = 300;
        $echo->nickname = 'ec';
        $echo->fuel = QueryFuel::Electric;

        foreach ([$alpha, $bravo, $charlie, $delta, $echo] as $vehicle) {
            $this->persistence->save($vehicle);
        }
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    private function maker(string $name, ?QueryCountry $country): QueryMaker {
        $maker = new QueryMaker();
        $maker->name = $name;
        $maker->country = $country;
        $this->persistence->save($maker);

        return $maker;
    }

    private function vehicles(): Query {
        return $this->persistence->query(QueryVehicle::class);
    }

    /** @return list<string> The names a query matches, in order: every page. */
    private static function names(Query $query): array {
        $names = [];
        $query = $query->limit(100);
        do {
            $page = $query->page();
            foreach ($page->items as $item) {
                $names[] = $item->name;
            }
            $query = $query->after($page->nextCursor);
        } while (!$page->isLast());

        return $names;
    }

    public function testComparisonsMatchWhatALoadShows(): void {
        self::assertSame(['Bravo', 'Charlie', 'Echo'], self::names($this->vehicles()->where('price', Is::greaterThan(100))));
        self::assertSame(['Bravo', 'Charlie'], self::names($this->vehicles()->where('price', Is::greaterThanOrEqualTo(200))->where('fuel', Is::equalTo(QueryFuel::Diesel))));
        self::assertSame(['Bravo'], self::names($this->vehicles()->where('chassis:weight', Is::lessThan(1000))));
        self::assertSame(['Delta', 'Echo'], self::names($this->vehicles()->where('chassis:weight', Is::equalTo(1000))), 'Untouched, at the default: stored all the same.');
    }

    public function testInLikeAndNull(): void {
        self::assertSame(['Alpha', 'Echo'], self::names($this->vehicles()->where('name', Is::in(['Echo', 'Alpha']))));
        self::assertSame(['Bravo'], self::names($this->vehicles()->where('name', Is::like('Br%'))));
        self::assertSame(['Bravo', 'Delta'], self::names($this->vehicles()->where('nickname', Is::null())));
        self::assertSame(['Alpha', 'Charlie', 'Echo'], self::names($this->vehicles()->where('nickname', Is::notNull())));
        self::assertSame(['Bravo', 'Charlie', 'Delta', 'Echo'], self::names($this->vehicles()->where('nickname', Is::notEqualTo('al'))), 'A null is not equal to "al".');
    }

    public function testReferencesAndBooleans(): void {
        self::assertSame(['Alpha', 'Charlie'], self::names($this->vehicles()->where('maker', Is::equalTo($this->acme))));
        self::assertSame(['Delta', 'Echo'], self::names($this->vehicles()->where('maker', Is::null())));
        self::assertSame(['Charlie'], self::names($this->vehicles()->where('sold', Is::equalTo(true))));
        self::assertSame(['Alpha', 'Bravo', 'Delta', 'Echo'], self::names($this->vehicles()->where('sold', Is::equalTo(false))));
    }

    public function testAQueryCoversItsSubtypesEachLoadedAsItself(): void {
        $page = $this->vehicles()->limit(10)->page();

        self::assertSame([QueryCar::class, QueryCar::class, QueryTruck::class, QueryVehicle::class, QueryCar::class], array_map(get_class(...), $page->items));
        self::assertSame(['Alpha', 'Bravo', 'Echo'], self::names($this->persistence->query(QueryCar::class)));
        self::assertSame(['Alpha'], self::names($this->persistence->query(QueryCar::class)->where('doors', Is::equalTo(2))));
    }

    public function testAPathStepsThroughReferences(): void {
        self::assertSame(['Alpha', 'Charlie'], self::names($this->vehicles()->where('maker.name', Is::equalTo('Acme'))));
        self::assertSame(['Alpha', 'Charlie'], self::names($this->vehicles()->where('maker.country.name', Is::equalTo('Italy'))));
        self::assertSame(['Bravo', 'Delta', 'Echo'], self::names($this->vehicles()->where('maker.country.name', Is::null())), 'No maker, or a maker with no country: nothing there.');
    }

    public function testAPathStepsThroughOwnedObjects(): void {
        self::assertSame(['Alpha', 'Charlie'], self::names($this->vehicles()->where('engine.horsepower', Is::greaterThan(200))));
        self::assertSame(['Alpha'], self::names($this->vehicles()->where('engine.turbo.boost', Is::equalTo(5))));
        self::assertSame(['Bravo', 'Delta', 'Echo'], self::names($this->vehicles()->where('engine.horsepower', Is::null())));
    }

    public function testSortingThroughAPathPutsAnEmptyRelationshipFirst(): void {
        self::assertSame(
            ['Delta', 'Echo', 'Alpha', 'Charlie', 'Bravo'],
            self::pagedNames($this->vehicles()->where('maker.name', Is::notEqualTo('Nobody'))->orderBy('maker.name')),
            'No maker sorts lowest; the condition and the sort share one join.',
        );
    }

    public function testOwnedEntitiesAreNeverListedOnTheirOwn(): void {
        self::assertSame(0, $this->persistence->query(QueryEngine::class)->count());
        self::assertGreaterThan(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM entities WHERE owner_field = 'engine'"));
    }

    public function testPagesFollowEachOtherWithoutGapOrRepeatThroughTies(): void {
        $query = $this->vehicles()->orderBy('price', Order::Descending)->limit(2);

        $first = $query->page();
        $second = $query->after($first->nextCursor)->page();
        $third = $query->after($second->nextCursor)->page();

        $names = static fn (array $items): array => array_map(static fn (object $item): string => $item->name, $items);
        self::assertSame(['Echo', 'Bravo'], $names($first->items));
        self::assertSame(['Charlie', 'Alpha'], $names($second->items), 'Bravo and Charlie tie at 200: the id breaks it.');
        self::assertSame(['Delta'], $names($third->items));
        self::assertTrue($third->isLast());
    }

    public function testANullSortsLowest(): void {
        self::assertSame(['Bravo', 'Delta', 'Alpha', 'Charlie', 'Echo'], self::pagedNames($this->vehicles()->orderBy('nickname')));
        self::assertSame(['Echo', 'Charlie', 'Alpha', 'Bravo', 'Delta'], self::pagedNames($this->vehicles()->orderBy('nickname', Order::Descending)));
    }

    /** @return list<string> Every name, two a page. */
    private static function pagedNames(Query $query): array {
        $names = [];
        $query = $query->limit(2);
        do {
            $page = $query->page();
            array_push($names, ...array_map(static fn (object $item): string => $item->name, $page->items));
            $query = $query->after($page->nextCursor);
        } while (!$page->isLast());

        return $names;
    }

    public function testCountAndIdsShareTheFilter(): void {
        $query = $this->vehicles()->where('price', Is::greaterThan(100))->orderBy('price')->limit(1);

        self::assertSame(3, $query->count(), 'A limit never changes the count.');
        self::assertCount(1, $query->ids());
        self::assertCount(2, $query->after($query->page()->nextCursor)->limit(10)->ids());
    }

    public function testAMigrationPendingRefusesToQuery(): void {
        TestDatabase::dropEverything($this->connection);

        $this->expectException(MigrationPending::class);
        Persistence::boot($this->connection, [new QueryModule()])->query(QueryVehicle::class)->count();
    }

    /** @return array<string, array{\Closure(self): mixed, string}> */
    public static function misuses(): array {
        return [
            'an unknown field' => [static fn (self $test): mixed => $test->vehicles()->where('colour', Is::equalTo('red'))->count(), '"qt:vehicle" has no field "colour"'],
            'a subtype\'s field' => [static fn (self $test): mixed => $test->vehicles()->where('doors', Is::equalTo(2))->count(), '"qt:vehicle" has no field "doors"'],
            'an order on an enum' => [static fn (self $test): mixed => $test->vehicles()->where('fuel', Is::greaterThan(QueryFuel::Diesel))->count(), '"fuel" has no order to compare by.'],
            'LIKE on a number' => [static fn (self $test): mixed => $test->vehicles()->where('price', Is::like('1%'))->count(), '"price" isn\'t text'],
            'a value of the wrong type' => [static fn (self $test): mixed => $test->vehicles()->where('price', Is::equalTo('cheap'))->count(), '"price": a Int scalar must hold a int, got string.'],
            'an entity never saved' => [static fn (self $test): mixed => $test->vehicles()->where('maker', Is::equalTo(new QueryMaker()))->count(), 'was never saved'],
            'a cursor from another ordering' => [static fn (self $test): mixed => $test->vehicles()->orderBy('name')->limit(1)->after($test->vehicles()->orderBy('price')->limit(1)->page()->nextCursor)->page(), 'sorted by price asc, not name asc'],
            'a page without a limit' => [static fn (self $test): mixed => $test->vehicles()->page(), 'page() needs a limit()'],
            'an owned object itself' => [static fn (self $test): mixed => $test->vehicles()->where('engine', Is::null())->count(), '"engine" is an owned object: name one of its fields'],
            'a step into a Collection' => [static fn (self $test): mixed => $test->vehicles()->where('spares.horsepower', Is::equalTo(1))->count(), '"spares" is an Owned Collection'],
            'a step through a value' => [static fn (self $test): mixed => $test->vehicles()->where('name.length', Is::equalTo(1))->count(), '"name" holds a value, not a relationship'],
            'an unknown field past a step' => [static fn (self $test): mixed => $test->vehicles()->where('maker.doors', Is::equalTo(1))->count(), '"qt:maker" has no field "doors"'],
        ];
    }

    /** @param \Closure(self): mixed $misuse */
    #[\PHPUnit\Framework\Attributes\DataProvider('misuses')]
    public function testAMisuseFailsSayingWhy(\Closure $misuse, string $message): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $misuse($this);
    }
}
