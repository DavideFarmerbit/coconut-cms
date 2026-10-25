<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRows;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRowsBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\Placement;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\Row;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

final class EntityRowsBuilderTest extends TestCase
{
    private IdentityMap $identities;
    private NativeEntityValues $values;
    private EntityRowsBuilder $builder;

    /** @var list<string> Each path ownedIdOf() was asked for, in order. */
    private array $askedFor = [];

    protected function setUp(): void {
        $stores = new InMemorySchemaStores();
        $stores->compiler()->compile(NativeEntityReader::readAll(RowsFixtures::CLASSES));

        $this->identities = new IdentityMap();
        $this->values = new NativeEntityValues($stores->registry, $stores->values, $this->identities);
        $this->builder = new EntityRowsBuilder(new SchemaLookup($stores->registry));
    }

    /** @return list<EntityRows> */
    private function rowsOf(object $entity): array {
        return $this->builder->rowsOf($this->values->of($entity), 'car-id', Placement::standalone(), function (FieldPath $path): string {
            $this->askedFor[] = (string) $path;

            return 'id-of-' . $path;
        });
    }

    /** @param list<Row> $rows */
    private static function rowIn(array $rows, string $table): array {
        foreach ($rows as $row) {
            if ($row->table === $table) {
                return $row->values;
            }
        }

        self::fail(sprintf('No row in "%s".', $table));
    }

    /**
     * @param list<Row> $rows
     * @return list<array<string, mixed>>
     */
    private static function rowsIn(array $rows, string $table): array {
        return array_values(array_map(
            static fn (Row $row): array => $row->values,
            array_filter($rows, static fn (Row $row): bool => $row->table === $table),
        ));
    }

    public function testAnUntouchedEntityIsStoredWholeWithEveryFlagClear(): void {
        $all = $this->rowsOf(new RowsCar());

        self::assertCount(2, $all, 'The car, and its default engine.');
        self::assertSame(
            ['entities', 'rows_vehicle', '_state__rows_vehicle', 'rows_car', '_state__rows_car'],
            array_map(static fn (Row $row): string => $row->table, $all[0]->rows),
        );
        self::assertSame(
            ['id' => 'car-id', 'owner' => EntityIds::NIL, 'owner_field' => '', 'position' => -1, 'concrete_identifier' => 'default:rows_car'],
            self::rowIn($all[0]->rows, 'entities'),
        );
        self::assertSame(
            ['_id' => 'car-id', '_owner' => EntityIds::NIL, '_owner_field' => '', 'chassis__weight' => 1000, 'maker' => null, '_spares_count' => 0],
            self::rowIn($all[0]->rows, 'rows_car'),
        );
        self::assertSame(
            ['_id' => 'car-id', 'chassis__weight' => false, 'maker' => false, 'engine' => false, 'spares' => false, 'wheels' => false, 'tags' => false],
            self::rowIn($all[0]->rows, '_state__rows_car'),
        );
    }

    public function testAnUntouchedOwnedObjectIsStoredWithItsFlagsClear(): void {
        $all = $this->rowsOf(new RowsCar());

        self::assertSame(['engine'], $this->askedFor);
        self::assertSame(
            ['_id' => 'id-of-engine', '_owner' => 'car-id', '_owner_field' => 'engine', 'horsepower' => 300],
            self::rowIn($all[1]->rows, 'rows_engine'),
        );
        self::assertSame(['_id' => 'id-of-engine', 'horsepower' => false, 'turbo' => false], self::rowIn($all[1]->rows, '_state__rows_engine'));
    }

    public function testATouchedValueLandsOnTheLevelDeclaringItWithItsFlagSet(): void {
        $car = new RowsCar();
        $car->name = 'mine';
        $car->chassis->weight = 1200;
        $maker = new RowsMaker();
        $this->identities->remember($maker, 'maker-id');
        $car->maker = $maker;
        $rows = $this->rowsOf($car)[0]->rows;

        self::assertSame('mine', self::rowIn($rows, 'rows_vehicle')['name']);
        self::assertTrue(self::rowIn($rows, '_state__rows_vehicle')['name']);
        self::assertSame(1200, self::rowIn($rows, 'rows_car')['chassis__weight']);
        self::assertSame('maker-id', self::rowIn($rows, 'rows_car')['maker']);
        self::assertTrue(self::rowIn($rows, '_state__rows_car')['chassis__weight']);
    }

    public function testAnOwnedObjectWithAFieldSetGetsRowsOfItsOwn(): void {
        $car = new RowsCar();
        $car->engine->horsepower = 450;
        $all = $this->rowsOf($car);

        self::assertCount(2, $all);
        self::assertTrue(self::rowIn($all[0]->rows, '_state__rows_car')['engine']);
        self::assertSame('id-of-engine', $all[1]->id);
        self::assertSame('default:rows_engine', $all[1]->identifier);
        self::assertSame(
            ['id' => 'id-of-engine', 'owner' => 'car-id', 'owner_field' => 'engine', 'position' => -1, 'concrete_identifier' => 'default:rows_engine'],
            self::rowIn($all[1]->rows, 'entities'),
        );
        self::assertSame(
            ['_id' => 'id-of-engine', '_owner' => 'car-id', '_owner_field' => 'engine', 'horsepower' => 450],
            self::rowIn($all[1]->rows, 'rows_engine'),
        );
    }

    public function testSomethingOwnedTwoLevelsDownIsOwnedByItsDirectOwner(): void {
        $car = new RowsCar();
        $car->engine->turbo = new RowsTurbo();
        $car->engine->turbo->boost = 5;
        $all = $this->rowsOf($car);

        self::assertCount(3, $all);
        self::assertSame(['engine', 'engine.turbo'], $this->askedFor);
        self::assertSame('id-of-engine', self::rowIn($all[2]->rows, 'entities')['owner']);
        self::assertSame('turbo', self::rowIn($all[2]->rows, 'entities')['owner_field']);
        self::assertSame(5, self::rowIn($all[2]->rows, 'rows_turbo')['boost']);
    }

    public function testClearingAnOwnedObjectFlagsItButGivesItNoRows(): void {
        $car = new RowsCar();
        $car->engine = null;
        $all = $this->rowsOf($car);

        self::assertCount(1, $all);
        self::assertTrue(self::rowIn($all[0]->rows, '_state__rows_car')['engine']);
    }

    public function testAnOwnedCollectionStoresItsCountAndAnEntityPerItemSkippingEmptySlots(): void {
        $car = new RowsCar();
        $car->spares = [new RowsEngine(), null, new RowsEngine()];
        $all = $this->rowsOf($car);

        self::assertSame(3, self::rowIn($all[0]->rows, 'rows_car')['_spares_count']);
        self::assertTrue(self::rowIn($all[0]->rows, '_state__rows_car')['spares']);
        self::assertSame(['engine', 'spares.0', 'spares.2'], $this->askedFor);
        self::assertSame(
            [['spares', 0], ['spares', 2]],
            array_map(static fn (EntityRows $entity): array => [self::rowIn($entity->rows, 'entities')['owner_field'], self::rowIn($entity->rows, 'entities')['position']], array_slice($all, 2)),
        );
    }

    public function testAnUntouchedCollectionStoresItsDefaultItemsUnflagged(): void {
        $rows = $this->rowsOf(new RowsGarage())[0]->rows;

        self::assertFalse(self::rowIn($rows, '_state__rows_garage')['tools']);
        self::assertSame(
            [['_owner' => 'car-id', '_position' => 0, '_value' => 'jack'], ['_owner' => 'car-id', '_position' => 1, '_value' => 'wrench']],
            self::rowsIn($rows, 'rows_garage__tools'),
        );
    }

    public function testAScalarCollectionStoresEveryItemWhole(): void {
        $car = new RowsCar();
        $car->tags = ['red', 'fast'];
        $rows = $this->rowsOf($car)[0]->rows;

        self::assertSame(
            [['_owner' => 'car-id', '_position' => 0, '_value' => 'red'], ['_owner' => 'car-id', '_position' => 1, '_value' => 'fast']],
            self::rowsIn($rows, 'rows_car__tags'),
        );
    }

    public function testAnEmbedCollectionStoresEveryItemFieldAndFlagsOnlyTheOnesThatDiffer(): void {
        $car = new RowsCar();
        $car->wheels = [new RowsWheel(), new RowsWheel()];
        $car->wheels[1]->size = 19;
        $rows = $this->rowsOf($car)[0]->rows;

        self::assertSame(
            [['_owner' => 'car-id', '_position' => 0, 'size' => 17, 'maker' => null], ['_owner' => 'car-id', '_position' => 1, 'size' => 19, 'maker' => null]],
            self::rowsIn($rows, 'rows_car__wheels'),
        );
        self::assertSame(
            [['_owner' => 'car-id', '_position' => 0, 'size' => false, 'maker' => false], ['_owner' => 'car-id', '_position' => 1, 'size' => true, 'maker' => false]],
            self::rowsIn($rows, '_state__rows_car__wheels'),
        );
    }
}
