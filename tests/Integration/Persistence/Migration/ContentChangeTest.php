<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityTables;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityLoader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\StoredEntity;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\ContentChange;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationPlanner;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\Migrator;
use XyloIsCoding\CoconutCms\Persistence\Migration\TableChanges;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\FieldIdentities;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeViewClasses;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/**
 * The new release reading and saving while rows are still in the old shape.
 * V1 to V2 renames, retypes, gains a level, renames and drops a type and moves
 * a field: the same change MigratorTest runs as one move. Here no row moves,
 * and the expected values are that test's.
 */
final class ContentChangeTest extends TestCase
{
    private Connection $connection;
    private ContentChange $change;
    private SchemaRegistry $registry;
    private SchemaFieldValueStore $values;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        class_exists(MigratorV1::class);
        class_exists(MigratorV2::class);
        $this->migrateToV1();
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

    private function migrateToV1(): MigrationResult {
        [$schemas, $values, $registry] = $this->stores();

        return (new Migrator($this->connection, $registry, $schemas, $values))->migrate(NativeEntityReader::readAll(MigratorV1::CLASSES), MigrationSteps::none());
    }

    /** @return array<string, string> Three V1 cars, stored by the old release: their ids by name. */
    private function saveV1Cars(): array {
        [, $values, $registry] = $this->stores();
        $writer = new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap());

        $family = new MigV1Car();
        $family->name = 'Family car';
        $family->price = 150;
        $family->chassis->weight = 1200;
        $family->engine->horsepower = 450;
        $spare = new MigV1Engine();
        $spare->horsepower = 200;
        $family->spares = [$spare, new MigV1TurboEngine()];
        $family->tags = ['red', 'fast'];

        $racer = new MigV1Car();
        $racer->name = 'Racer';
        $racer->engine = new MigV1TurboEngine();

        return ['family' => $writer->save($family), 'racer' => $writer->save($racer), 'untouched' => $writer->save(new MigV1Car())];
    }

    /** Plans V2 and expands the tables, moving no row: the old schemas stay stored. */
    private function beginChange(): void {
        [$schemas, $values, $registry] = $this->stores();
        $this->registry = $registry;
        $this->values = $values;
        $stored = $schemas->all();
        $compiler = new SchemaCompiler($registry, $values, $schemas);
        $blueprints = NativeEntityReader::readAll(MigratorV2::CLASSES);
        $plan = (new MigrationPlanner())->plan($stored, $compiler->compute($blueprints)->schemas, MigratorV2::steps());
        $batch = FieldIdentities::assign($compiler->compute($blueprints), $plan->schemas->schemas(), array_map(static fn (StoredSchema $each): EntitySchema => $each->schema, $stored));

        $target = [...$plan->schemas->schemas(), ...$batch->schemas];
        $layouts = new LayoutBuilder(new SchemaLookup($registry, $target));
        $levels = array_map($layouts->levelOf(...), array_keys($target));
        (new TableChanges($this->connection))->expand(DatabaseSchema::of($levels));
        (new EntityTables($this->connection))->seed($levels);

        $this->change = new ContentChange($this->connection, 'test', $plan, $batch, Migrator::changedTypes($stored, $batch, $plan), $registry, $values);
        $this->change->createMarkers();
    }

    private function reader(): EntityReader {
        return new EntityReader($this->connection, $this->registry, $this->values, $this->change);
    }

    /** Loads $id as the new release does. */
    private function loadV2(string $id): MigV2Car {
        $classes = new NativeEntityRegistry();
        foreach (MigratorV2::CLASSES as $class) {
            $classes->register($class);
        }

        $schemas = new SchemaLookup($this->registry, $this->change->schemas());

        return (new NativeEntityLoader($this->reader(), new NativeViewClasses($classes, $schemas), new IdentityMap()))->load($id);
    }

    /** Saves $id as the new release does, with what's at $path changed. */
    private function saveV2(string $id, string $path, mixed $value): void {
        $writer = EntityWriter::during($this->change, $this->connection, $this->registry);
        $writer->writeWith($id, static function (?StoredEntity $stored) use ($path, $value): EntityValues {
            $dense = $stored->values->toArray();
            $dense[$path] = $value;
            $touched = $stored->touched->toArray();
            $touched[$path] = $value;

            return new EntityValues($stored->identifier, FieldValues::fromArray($dense), FieldValues::fromArray($touched));
        }, function (FieldPath $path) use ($id): string {
            return $this->reader()->read($id)?->ownedIds[(string) $path] ?? EntityIds::generate();
        });
    }

    /** Saves $id as the old release does: its code knows nothing of the change. */
    private function saveV1(string $id, int $price): void {
        [, $values, $registry] = $this->stores();
        $classes = new NativeEntityRegistry();
        foreach (MigratorV1::CLASSES as $class) {
            $classes->register($class);
        }

        $identities = new IdentityMap();
        $car = (new NativeEntityLoader(new EntityReader($this->connection, $registry, $values), new NativeViewClasses($classes, new SchemaLookup($registry)), $identities))->load($id);
        $car->price = $price;
        (new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, $identities))->save($car);
    }

    public function testALeftBehindAggregateReadsAsTheNewShapeWithNoRowMoved(): void {
        $ids = $this->saveV1Cars();
        $this->beginChange();

        $family = $this->loadV2($ids['family']);
        self::assertSame('Family car', $family->name);
        self::assertSame('150 EUR', $family->cost, 'Renamed, then retyped by its converter.');
        self::assertSame(['red', 'fast'], $family->tags, 'Moved up a level.');
        self::assertSame(4, $family->wheelCount, 'From a level it has no row at.');
        self::assertSame(1200.0, $family->chassis->weight, 'An Embed\'s field, retyped.');
        self::assertSame('steel', $family->chassis->material);
        self::assertInstanceOf(MigV2Motor::class, $family->engine);
        self::assertSame(450, $family->engine->horsepower);
        self::assertSame('SN-450', $family->engine->serial, 'An owned object of a renamed type.');
        self::assertCount(2, $family->spares);
        self::assertSame('SN-200', $family->spares[0]->serial);
        self::assertNull($family->spares[1], 'A turbo engine: its type is gone.');

        $racer = $this->loadV2($ids['racer']);
        self::assertNull($racer->engine, 'A turbo engine: its type is gone.');
        self::assertSame('0 EUR', $racer->cost);

        $untouched = $this->loadV2($ids['untouched']);
        self::assertSame(1000.0, $untouched->chassis->weight);
        self::assertSame('', $untouched->engine->serial, 'A default motor keeps following the default.');
    }

    public function testReadingChangesNoRow(): void {
        $ids = $this->saveV1Cars();
        $this->beginChange();
        $before = $this->connection->fetchAllAssociative('SELECT * FROM migtest_car ORDER BY _id');

        $this->loadV2($ids['family']);

        self::assertEquals($before, $this->connection->fetchAllAssociative('SELECT * FROM migtest_car ORDER BY _id'));
        self::assertTrue($this->change->isLeftBehind($ids['family']), 'Still left behind: a read marks nothing.');
    }

    public function testASaveByTheNewReleaseLeavesItInTheNewShapeAndMarked(): void {
        $ids = $this->saveV1Cars();
        $this->beginChange();

        $this->saveV2($ids['family'], 'name', 'Renamed');

        self::assertFalse($this->change->isLeftBehind($ids['family']));
        $family = $this->loadV2($ids['family']);
        self::assertSame('Renamed', $family->name);
        self::assertSame('150 EUR', $family->cost, 'Read from the new column now.');
        self::assertSame(1200.0, $family->chassis->weight);
        self::assertSame(4, $family->wheelCount, 'The gained level has its row now.');
        self::assertSame('SN-450', $family->engine->serial);
    }

    public function testAnyLaterTokenLeavesAMarkedAggregateBehindAgain(): void {
        $ids = $this->saveV1Cars();
        $this->beginChange();
        $this->saveV2($ids['untouched'], 'name', 'New');
        self::assertFalse($this->change->isLeftBehind($ids['untouched']));

        $this->connection->executeStatement('UPDATE entities SET written = ? WHERE id = ?', [EntityIds::generate(), $ids['untouched']]);

        self::assertTrue($this->change->isLeftBehind($ids['untouched']), 'However the row was written.');
    }

    public function testAnOldReleaseSaveReachesTheNextLoad(): void {
        $ids = $this->saveV1Cars();
        $this->beginChange();

        $this->saveV1($ids['family'], 999);

        self::assertSame('999 EUR', $this->loadV2($ids['family'])->cost);
    }

    public function testAnAggregateHoldingNoChangedTypeIsNeverLeftBehind(): void {
        $this->beginChange();

        self::assertFalse($this->change->isLeftBehind(EntityIds::NIL), 'The Cms row: nothing changes it.');
        self::assertFalse($this->change->isLeftBehind(EntityIds::generate()), 'No such entity.');
    }

    public function testDeletingAnEntityTakesItsMarkerWithIt(): void {
        $ids = $this->saveV1Cars();
        $this->beginChange();
        $this->saveV2($ids['family'], 'name', 'Renamed');

        EntityWriter::during($this->change, $this->connection, $this->registry)->delete($ids['family']);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM _seen__test WHERE id = ?', [$ids['family']]));
    }
}
