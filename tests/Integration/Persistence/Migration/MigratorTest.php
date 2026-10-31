<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationUnfinished;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityLoader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationBlocked;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\Migrator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeViewClasses;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;
use XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\ConvertsAnyField;

/** Fails on a car whose price is 999: a converter bug only real content shows. */
final class MigFailingPriceToText implements FieldConverter
{
    use ConvertsAnyField;

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->value() === 999) {
            throw new RuntimeException('cannot convert 999');
        }

        return (new MigPriceToText())->convert($old, $to);
    }
}

/** Keeps the price an int: not what a String field holds. */
final class MigPriceKeptAsInt implements FieldConverter
{
    use ConvertsAnyField;

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        return Conversion::of($old->value());
    }
}

/** Writes a value under the price, as if it were an Embed: no field is there. */
final class MigPriceWithAStrayValue implements FieldConverter
{
    use ConvertsAnyField;

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        return Conversion::of('0 EUR', ['currency' => 'EUR']);
    }
}

final class MigratorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        $this->dropEverything();
    }

    protected function tearDown(): void {
        $this->dropEverything();
        $this->connection->close();
    }

    private function dropEverything(): void {
        TestDatabase::dropEverything($this->connection);
    }

    /** @return array{SchemaStore, SchemaFieldValueStore, SchemaRegistry} What a new request starts with. */
    private function stores(): array {
        $codec = new EntitySchemaCodec(new FieldValidatorRegistry(), new PrototypeValidatorRegistry(), new ValueObjectRegistry(), new EnumRegistry());
        $schemas = new SchemaStore($this->connection, $codec);

        return [$schemas, new SchemaFieldValueStore($this->connection), new SchemaRegistry($schemas)];
    }

    /** @param list<class-string> $classes */
    private function migrate(array $classes, MigrationSteps $steps): MigrationResult {
        [$schemas, $values, $registry] = $this->stores();

        return (new Migrator($this->connection, $registry, $schemas, $values))->migrate(NativeEntityReader::readAll($classes), $steps);
    }

    /** @return array<string, string> Three V1 cars, stored: their ids by name. */
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

    private function loadV2(string $id): MigV2Car {
        [, $values, $registry] = $this->stores();
        $classes = new NativeEntityRegistry();
        foreach (MigratorV2::CLASSES as $class) {
            $classes->register($class);
        }

        return (new NativeEntityLoader(new EntityReader($this->connection, $registry, $values), new NativeViewClasses($classes, new SchemaLookup($registry)), new IdentityMap()))->load($id);
    }

    /** @return list<string> Every table and column name a migration moved aside, "table.column". */
    private function movedAside(): array {
        $found = [];
        foreach ($this->connection->createSchemaManager()->introspectSchema()->getTables() as $table) {
            if (str_starts_with($table->getName(), '_old__')) {
                $found[] = $table->getName();
            }
            foreach ($table->getColumns() as $column) {
                if (str_starts_with($column->getName(), '_old__')) {
                    $found[] = $table->getName() . '.' . $column->getName();
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function tables(): array {
        return $this->connection->createSchemaManager()->listTableNames();
    }

    /** V2's steps, with the price turned into the cost by $converter. */
    private static function stepsConvertingPriceWith(FieldConverter $converter): MigrationSteps {
        $steps = MigrationSteps::none()
            ->add('rename-price', [new RenameField('migtest:car', 'price', 'cost')])
            ->add('cost-as-text', [RetypeField::converting('migtest:car', 'cost', $converter)]);
        foreach (MigratorV2::steps()->all() as $step) {
            if ($step->id === 'restructure') {
                $steps = $steps->add($step->id, $step->fixups);
            }
        }

        return $steps;
    }

    /** Migrates V1 cars with $steps, expecting the move to fail with a message holding $message. */
    private function assertMoveFails(MigrationSteps $steps, string $message): void {
        $this->migrate(MigratorV1::CLASSES, MigrationSteps::none());
        $this->saveV1Cars();

        try {
            $this->migrate(MigratorV2::CLASSES, $steps);
            self::fail('The move should have failed.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString($message, $failure->getMessage());
        }

        [$schemas] = $this->stores();
        self::assertNotNull($schemas->load('migtest:engine'), 'Nothing moved.');
    }

    public function testAFreshDatabaseIsCreatedFromTheCodeWithEveryStepRecordedAsApplied(): void {
        $result = $this->migrate(MigratorV2::CLASSES, MigratorV2::steps());

        self::assertTrue($result->fresh);
        self::assertSame(['rename-price', 'cost-as-text', 'restructure'], $result->recorded);
        self::assertContains('migtest_car', $this->tables());
        self::assertContains('_state__migtest_motor', $this->tables());
        self::assertSame('coconut-cms:cms', $this->connection->fetchOne('SELECT concrete_identifier FROM entities'));

        $again = $this->migrate(MigratorV2::CLASSES, MigratorV2::steps());
        self::assertFalse($again->fresh);
        self::assertSame([], $again->recorded);
        self::assertTrue($again->plan->isEmpty());
        self::assertSame($result->stateKey, $again->stateKey);
        self::assertSame($result->stateKey, (new MigrationState($this->connection))->read()['stateKey']);
    }

    public function testAStepWithNoSchemaChangeStillChangesTheStateKey(): void {
        $before = $this->migrate(MigratorV2::CLASSES, MigratorV2::steps());

        $after = $this->migrate(MigratorV2::CLASSES, MigratorV2::steps()->add('data-only', []));

        self::assertNotSame($before->stateKey, $after->stateKey);
        self::assertSame(['data-only'], $after->recorded);
        self::assertSame($after->stateKey, (new MigrationState($this->connection))->read()['stateKey']);
    }

    public function testMigratingMovesEveryAggregateIntoTheNewShape(): void {
        $this->migrate(MigratorV1::CLASSES, MigrationSteps::none());
        $ids = $this->saveV1Cars();

        $result = $this->migrate(MigratorV2::CLASSES, MigratorV2::steps());

        self::assertSame(['rename-price', 'cost-as-text', 'restructure'], $result->recorded);
        self::assertSame(3, $result->moved, 'The three cars; Cms is unchanged, so it stays.');

        $family = $this->loadV2($ids['family']);
        self::assertSame('Family car', $family->name);
        self::assertSame('150 EUR', $family->cost);
        self::assertSame(['red', 'fast'], $family->tags);
        self::assertSame(4, $family->wheelCount);
        self::assertSame(1200.0, $family->chassis->weight, 'Read from the column moved aside, converted.');
        self::assertSame('steel', $family->chassis->material);
        self::assertInstanceOf(MigV2Motor::class, $family->engine);
        self::assertSame(450, $family->engine->horsepower);
        self::assertSame('SN-450', $family->engine->serial);
        self::assertCount(2, $family->spares);
        self::assertSame('SN-200', $family->spares[0]->serial);
        self::assertNull($family->spares[1], 'A turbo engine: its type is gone.');

        $racer = $this->loadV2($ids['racer']);
        self::assertNull($racer->engine, 'A turbo engine: its type is gone.');
        self::assertSame('0 EUR', $racer->cost);

        $untouched = $this->loadV2($ids['untouched']);
        self::assertSame(1000.0, $untouched->chassis->weight);
        self::assertSame(300, $untouched->engine->horsepower);
        self::assertSame('', $untouched->engine->serial, 'A default motor keeps following the default.');
        self::assertSame(4, $untouched->wheelCount);
    }

    public function testMigratingLeavesNoTraceOfTheOldShape(): void {
        $this->migrate(MigratorV1::CLASSES, MigrationSteps::none());
        $this->saveV1Cars();

        $this->migrate(MigratorV2::CLASSES, MigratorV2::steps());

        self::assertSame([], $this->movedAside());
        self::assertNull((new MigrationState($this->connection))->read()['startedAt']);
        self::assertNotContains('migtest_engine', $this->tables());
        self::assertNotContains('migtest_turbo_engine', $this->tables());
        self::assertContains('migtest_motor', $this->tables());
        [$schemas] = $this->stores();
        self::assertNull($schemas->load('migtest:engine'));
        self::assertNotNull($schemas->load('migtest:motor'));
    }

    public function testABlockedMigrationChangesNothing(): void {
        $this->migrate(MigratorV1::CLASSES, MigrationSteps::none());
        $this->saveV1Cars();
        $tables = $this->tables();

        try {
            $this->migrate(MigratorV2::CLASSES, MigrationSteps::none());
            self::fail('The migration should have been blocked.');
        } catch (MigrationBlocked) {
        }

        self::assertSame($tables, $this->tables());
        [$schemas] = $this->stores();
        self::assertNotNull($schemas->load('migtest:engine'));
    }

    public function testAMoveThatFailsRollsBackAndTheFixedStepFinishesTheMigration(): void {
        $this->migrate(MigratorV1::CLASSES, MigrationSteps::none());
        $ids = $this->saveV1Cars();
        $this->connection->executeStatement("UPDATE migtest_car SET price = 999 WHERE _id = ?", [$ids['racer']]);
        $this->connection->executeStatement("UPDATE _state__migtest_car SET price = 1 WHERE _id = ?", [$ids['racer']]);

        try {
            $this->migrate(MigratorV2::CLASSES, self::stepsConvertingPriceWith(new MigFailingPriceToText()));
            self::fail('The move should have failed.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString(sprintf('Moving entity %s failed: cannot convert 999', $ids['racer']), $failure->getMessage());
        }

        // Nothing moved, nothing recorded; expand's work is still there.
        [$schemas] = $this->stores();
        self::assertNotNull($schemas->load('migtest:engine'));
        self::assertSame([], $this->connection->fetchFirstColumn('SELECT id FROM migration_steps'));
        self::assertSame(1200, (int) $this->connection->fetchOne('SELECT _old__chassis__weight FROM migtest_car WHERE _id = ?', [$ids['family']]));

        // Until a migration finishes, entities can't be read or written.
        try {
            $this->loadV2($ids['family']);
            self::fail('Reading should be refused.');
        } catch (MigrationUnfinished) {
        }
        [, , $registry] = $this->stores();
        try {
            EntityWriter::of($this->connection, $registry)->delete($ids['family']);
            self::fail('Writing should be refused.');
        } catch (MigrationUnfinished) {
        }

        // The fixed step runs over what expand already did, and finishes.
        $this->migrate(MigratorV2::CLASSES, MigratorV2::steps());

        self::assertSame('999 EUR', $this->loadV2($ids['racer'])->cost);
        self::assertSame('150 EUR', $this->loadV2($ids['family'])->cost);
        self::assertSame([], $this->movedAside());
        self::assertNull((new MigrationState($this->connection))->read()['startedAt']);
    }

    public function testAConvertedValueTheNewFieldCantHoldFailsTheMove(): void {
        $this->assertMoveFails(self::stepsConvertingPriceWith(new MigPriceKeptAsInt()), '"cost" must hold a string of 255 characters at most, got 150.');
    }

    public function testAReferenceLeftPointingAtTheWrongTypeFailsTheMigration(): void {
        $this->migrate(MigratorReferences::V1, MigrationSteps::none());
        [, $values, $registry] = $this->stores();
        $writer = new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap());
        $car = new MigRefCar();
        $carId = $writer->save($car);
        $garage = new MigRefV1Garage();
        $garage->vehicle = $car;
        $garageId = $writer->save($garage);

        try {
            $this->migrate(MigratorReferences::V2, MigratorReferences::steps());
            self::fail('The migration should have failed.');
        } catch (RuntimeException $failure) {
            self::assertSame(
                sprintf('Entity %s: "vehicle" points at %s, a "migref:car", which is neither "migref:bike" nor a type extending it.', $garageId, $carId),
                $failure->getMessage(),
            );
        }

        self::assertSame([], $this->connection->fetchFirstColumn('SELECT id FROM migration_steps'));
    }

    public function testAReferenceToAnEntityItsOwnAggregateDropsBecomesNull(): void {
        $this->migrate(MigratorDroppedTarget::V1, MigrationSteps::none());
        [, $values, $registry] = $this->stores();
        $writer = new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap());
        $car = new MigDropV1Car();
        $kept = new MigDropEngine();
        $turbo = new MigDropTurboEngine();
        $car->spares = [$kept, $turbo];
        $carId = $writer->save($car);
        $car->favorite = $turbo;
        $writer->save($car);

        $this->migrate(MigratorDroppedTarget::V2, MigratorDroppedTarget::steps());

        [, $values, $registry] = $this->stores();
        $classes = new NativeEntityRegistry();
        foreach (MigratorDroppedTarget::V2 as $class) {
            $classes->register($class);
        }
        $loaded = (new NativeEntityLoader(new EntityReader($this->connection, $registry, $values), new NativeViewClasses($classes, new SchemaLookup($registry)), new IdentityMap()))->load($carId);

        self::assertNull($loaded->favorite, 'Its target is gone.');
        self::assertInstanceOf(MigDropEngine::class, $loaded->spares[0]);
        self::assertNull($loaded->spares[1], 'A turbo engine: its type is gone.');
    }

    public function testAReferenceIntoAnotherAggregateWhoseTargetIsDroppedBecomesNull(): void {
        $this->migrate(MigratorDroppedTarget::V1, MigrationSteps::none());
        [, $values, $registry] = $this->stores();
        $writer = new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap());
        // One pointing car saved before the owner, one after: whichever order
        // the migration moves them in.
        $before = new MigDropV1Car();
        $writer->save($before);
        $owner = new MigDropV1Car();
        $owner->spares = [new MigDropEngine(), new MigDropTurboEngine()];
        $ownerId = $writer->save($owner);
        $after = new MigDropV1Car();
        $keeper = new MigDropV1Car();
        $before->favorite = $owner->spares[1];
        $after->favorite = $owner->spares[1];
        $keeper->favorite = $owner->spares[0];
        $beforeId = $writer->save($before);
        $afterId = $writer->save($after);
        $keeperId = $writer->save($keeper);

        $this->migrate(MigratorDroppedTarget::V2, MigratorDroppedTarget::steps());

        [, $values, $registry] = $this->stores();
        $classes = new NativeEntityRegistry();
        foreach (MigratorDroppedTarget::V2 as $class) {
            $classes->register($class);
        }
        $loader = new NativeEntityLoader(new EntityReader($this->connection, $registry, $values), new NativeViewClasses($classes, new SchemaLookup($registry)), new IdentityMap());

        self::assertNull($loader->load($beforeId)->favorite, 'Its target is gone.');
        self::assertNull($loader->load($afterId)->favorite, 'Its target is gone.');
        self::assertSame($loader->load($ownerId)->spares[0], $loader->load($keeperId)->favorite, 'A kept target is still the same engine.');
    }

    /** @return array{untouched: string, touched: string} Two V1 cars: one all defaults, one with its color and engine set. */
    private function saveDefaultsCars(): array {
        $this->migrate(MigratorDefaults::V1, MigrationSteps::none());
        [, $values, $registry] = $this->stores();
        $writer = new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap());
        $touched = new MigDefV1Car();
        $touched->color = 'green';
        $touched->engine->horsepower = 500;

        return ['untouched' => $writer->save(new MigDefV1Car()), 'touched' => $writer->save($touched)];
    }

    /** @return array{id: string, concrete_identifier: string} The engine stored under car $carId. */
    private function engineOf(string $carId): array {
        return $this->connection->fetchAssociative("SELECT id, concrete_identifier FROM entities WHERE owner = ? AND owner_field = 'engine'", [$carId]);
    }

    public function testAChangedDefaultRewritesWhatFollowsItAndNothingElse(): void {
        $cars = $this->saveDefaultsCars();
        $engines = array_map($this->engineOf(...), $cars);

        $result = $this->migrate(MigratorDefaults::V2, MigrationSteps::none());

        self::assertTrue($result->plan->isEmpty(), 'A default is never a difference.');
        self::assertSame(2, $result->moved, 'Both cars; Cms is unchanged, so it stays.');
        $color = fn (string $id): string => $this->connection->fetchOne('SELECT color FROM migdef_car WHERE _id = ?', [$id]);
        $power = fn (string $id): int => (int) $this->connection->fetchOne('SELECT horsepower FROM migdef_engine WHERE _id = ?', [$id]);
        self::assertSame('blue', $color($cars['untouched']));
        self::assertSame(200, $power($engines['untouched']['id']));
        self::assertSame('green', $color($cars['touched']));
        self::assertSame(500, $power($engines['touched']['id']));
        self::assertSame($engines, array_map($this->engineOf(...), $cars), 'Still the same engines.');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT color FROM _state__migdef_car WHERE _id = ?', [$cars['untouched']]));
    }

    public function testATouchedValueTheNewDefaultEqualsFollowsItFromThen(): void {
        $this->migrate(MigratorDefaults::V1, MigrationSteps::none());
        [, $values, $registry] = $this->stores();
        $car = new MigDefV1Car();
        $car->color = 'blue';
        $id = (new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap()))->save($car);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT color FROM _state__migdef_car WHERE _id = ?', [$id]));

        $this->migrate(MigratorDefaults::V2, MigrationSteps::none());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT color FROM _state__migdef_car WHERE _id = ?', [$id]), 'Blue is the default now.');
        self::assertSame('blue', $this->connection->fetchOne('SELECT color FROM migdef_car WHERE _id = ?', [$id]));
    }

    public function testADefaultNamingAnotherClassReplacesAnUntouchedOwnedObject(): void {
        $cars = $this->saveDefaultsCars();
        $engines = array_map($this->engineOf(...), $cars);

        $this->migrate(MigratorDefaults::V3, MigrationSteps::none());

        $untouched = $this->engineOf($cars['untouched']);
        self::assertSame('migdef:turbo_engine', $untouched['concrete_identifier']);
        self::assertNotSame($engines['untouched']['id'], $untouched['id'], 'Another object: a new id.');
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM entities WHERE id = ?', [$engines['untouched']['id']]));
        self::assertSame($engines['touched'], $this->engineOf($cars['touched']), 'Set by hand: it stays.');
    }

    public function testAValueConvertedWhereNoFieldIsFailsTheMove(): void {
        $this->assertMoveFails(self::stepsConvertingPriceWith(new MigPriceWithAStrayValue()), '"cost.currency" was written where "migtest:car" has no field.');
    }
}
