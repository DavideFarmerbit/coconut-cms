<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MoveValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\Migrator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** A migration moves the aggregates holding a changed type, and only those. */
final class MigratorScopeTest extends TestCase
{
    private Connection $connection;

    /** @var array<string, string> What setUp() saved: ids by name. */
    private array $ids;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(MigratorScope::class);

        $this->migrate(MigratorScope::V1, MigrationSteps::none());
        [, $values, $registry] = $this->stores();
        $writer = new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap());

        $turboCar = new ScopeCar();
        $turboCar->name = 'Turbo car';
        $turboCar->engine = new ScopeTurbo();
        $turboCar->engine->horsepower = 300;
        $turboCar->engine->boost = 7;
        $plainCar = new ScopeCar();
        $plainCar->name = 'Plain car';
        $plainCar->engine = new ScopeEngine();
        $plainCar->chassis->weight = 1300;
        $bareCar = new ScopeCar();
        $bareCar->name = 'Bare car';
        $maker = new ScopeMaker();
        $maker->name = 'Acme';

        $this->ids = [
            'turboCar' => $writer->save($turboCar),
            'plainCar' => $writer->save($plainCar),
            'bareCar' => $writer->save($bareCar),
            'maker' => $writer->save($maker),
            'turbo' => $writer->save(new ScopeTurbo()),
            'engine' => $writer->save(new ScopeEngine()),
        ];
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

    /** @param list<class-string> $classes */
    private function migrate(array $classes, MigrationSteps $steps): MigrationResult {
        [$schemas, $values, $registry] = $this->stores();

        return (new Migrator($this->connection, $registry, $schemas, $values))->migrate(NativeEntityReader::readAll($classes), $steps);
    }

    public function testATypeNothingElseHoldsMovesItsOwnAggregatesOnly(): void {
        $result = $this->migrate(MigratorScope::swapping([ScopeMaker::class], [ScopeMakerWithCountry::class]), MigrationSteps::none());

        self::assertSame(1, $result->moved, 'The maker; no car, no engine.');
        self::assertSame('IT', $this->connection->fetchOne('SELECT country FROM scope_maker WHERE _id = ?', [$this->ids['maker']]));
    }

    public function testASubtypeHeldWhereItsParentIsDeclaredMovesItsHolders(): void {
        $result = $this->migrate(MigratorScope::swapping([ScopeTurbo::class], [ScopeTurboWithWastegate::class]), MigrationSteps::none());

        self::assertSame(2, $result->moved, 'The car holding a turbo, and the standalone turbo.');
        $turboOfCar = $this->connection->fetchOne("SELECT id FROM entities WHERE owner = ? AND owner_field = 'engine'", [$this->ids['turboCar']]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT wastegate FROM scope_turbo WHERE _id = ?', [$turboOfCar]));
    }

    public function testAnEmbedTargetChangingMovesEveryHolder(): void {
        $result = $this->migrate(MigratorScope::swapping([ScopeChassis::class], [ScopeHeavierChassis::class]), MigrationSteps::none());

        self::assertSame(3, $result->moved, 'The three cars; no maker, no engine.');
    }

    public function testAParentChangingMovesWhatInheritsFromIt(): void {
        $result = $this->migrate(MigratorScope::swapping([ScopeEngine::class, ScopeTurbo::class], [ScopeStrongerEngine::class, ScopeTurboOfStrongerEngine::class]), MigrationSteps::none());

        self::assertSame(5, $result->moved, 'The three cars, which declare an engine, and both standalone engines; no maker.');
        self::assertSame(150, (int) $this->connection->fetchOne('SELECT horsepower FROM scope_engine WHERE _id = ?', [$this->ids['turbo']]));
    }

    public function testAStepMovingDataAloneMovesItsTypesAggregates(): void {
        $result = $this->migrate(MigratorScope::V1, MigrationSteps::none()->add('nickname-from-name', [new MoveValues('scope:car', 'name', 'nickname')]));

        self::assertSame(3, $result->moved, 'The three cars, though no schema changed.');
        self::assertSame('Plain car', $this->connection->fetchOne('SELECT nickname FROM scope_car WHERE _id = ?', [$this->ids['plainCar']]));
    }

    public function testAStepMovingDataAloneMovesItsSubtypesAndItsHoldersToo(): void {
        $result = $this->migrate(MigratorScope::V1, MigrationSteps::none()->add('torque-from-horsepower', [new MoveValues('scope:engine', 'horsepower', 'torque')]));

        self::assertSame(5, $result->moved, 'Both standalone engines, the turbo one included, and the three cars, which declare an engine.');
        $turboOfCar = $this->connection->fetchOne("SELECT id FROM entities WHERE owner = ? AND owner_field = 'engine'", [$this->ids['turboCar']]);
        self::assertSame(300, (int) $this->connection->fetchOne('SELECT torque FROM scope_engine WHERE _id = ?', [$turboOfCar]), 'A turbo is an engine: the step moves its values too.');
    }

    public function testAStepMovingDataAloneOnAnEmbedTargetMovesItsHolders(): void {
        $result = $this->migrate(MigratorScope::V1, MigrationSteps::none()->add('grade-from-weight', [new MoveValues('scope:chassis', 'weight', 'grade')]));

        self::assertSame(3, $result->moved, 'The three cars: a chassis has no entity of its own.');
        self::assertSame(1300, (int) $this->connection->fetchOne('SELECT chassis__grade FROM scope_car WHERE _id = ?', [$this->ids['plainCar']]));
    }

    public function testAFieldMovedUpIsInitializedWhereItIsGainedOnly(): void {
        $this->migrate(
            MigratorScope::swapping([ScopeEngine::class, ScopeTurbo::class], [ScopeEngineWithBoost::class, ScopeTurboOfEngineWithBoost::class]),
            MigrationSteps::none()->add('boost-up', [new MoveField('scope:turbo', 'boost', 'scope:engine', new ScopeBoostForPlainEngines())]),
        );

        $boostOf = fn (string $id): int => (int) $this->connection->fetchOne('SELECT boost FROM scope_engine WHERE _id = ?', [$id]);
        $engineOf = fn (string $car): string => $this->connection->fetchOne("SELECT id FROM entities WHERE owner = ? AND owner_field = 'engine'", [$car]);
        self::assertSame(1, $boostOf($this->ids['engine']), 'A plain engine gains it.');
        self::assertSame(1, $boostOf($engineOf($this->ids['plainCar'])), 'Owned or not.');
        self::assertSame(7, $boostOf($engineOf($this->ids['turboCar'])), 'A turbo had it: its value moves up with it.');
        self::assertSame(0, $boostOf($this->ids['turbo']), 'A turbo following its default follows the new one: never the initializer.');
    }

    public function testNothingChangedMovesNothing(): void {
        self::assertSame(0, $this->migrate(MigratorScope::V1, MigrationSteps::none())->moved);
    }
}
