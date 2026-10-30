<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationUnfinished;
use XyloIsCoding\CoconutCms\Persistence\MigrateOn;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\MigrationPending;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceStatus;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigratorV1;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigratorV2;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigV1Car;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigV2Car;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** The migration test's first version of the code, as a module. */
final class PersistenceV1Module implements Module
{
    public function name(): string {
        return 'migtest';
    }

    public function classes(): array {
        return array_values(array_diff(MigratorV1::CLASSES, [Cms::class]));
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }

    public function operations(): array {
        return [];
    }

    public function adminCode(): ?AdminCode {
        return null;
    }
}

/** Its second version, with the steps from the first. */
final class PersistenceV2Module implements Module
{
    public function name(): string {
        return 'migtest';
    }

    public function classes(): array {
        return array_values(array_diff(MigratorV2::CLASSES, [Cms::class]));
    }

    public function steps(): MigrationSteps {
        return MigratorV2::steps();
    }

    public function operations(): array {
        return [];
    }

    public function adminCode(): ?AdminCode {
        return null;
    }
}

final class PersistenceTest extends TestCase
{
    private Connection $connection;
    private string $cacheDirectory;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        $this->dropEverything();
        // The fixture files hold several classes each: load them by name.
        class_exists(MigratorV1::class);
        class_exists(MigratorV2::class);
        $this->cacheDirectory = sys_get_temp_dir() . '/coconut-cms-persistence-test-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDirectory);
    }

    protected function tearDown(): void {
        $this->dropEverything();
        $this->connection->close();
        foreach (glob($this->cacheDirectory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDirectory);
    }

    private function dropEverything(): void {
        TestDatabase::dropEverything($this->connection);
    }

    /** What a new request boots. */
    private function boot(Module $module, MigrateOn $migrateOn = MigrateOn::Command): Persistence {
        return Persistence::boot($this->connection, [$module], $this->cacheDirectory, $migrateOn);
    }

    public function testANeverMigratedDatabaseIsPendingUntilTheCommandMigratesIt(): void {
        $persistence = $this->boot(new PersistenceV1Module());

        self::assertSame(PersistenceStatus::MigrationPending, $persistence->status());
        try {
            $persistence->as(new SystemActor())->save(new MigV1Car());
            self::fail('Writing should be refused.');
        } catch (MigrationPending) {
        }

        $persistence->migrate();

        self::assertSame(PersistenceStatus::Ready, $persistence->status());
        $car = new MigV1Car();
        $car->name = 'Family car';
        $id = $persistence->as(new SystemActor())->save($car);
        self::assertSame('Family car', $this->boot(new PersistenceV1Module())->as(new SystemActor())->load($id)->name);
    }

    public function testNewCodeIsPendingAndMigratingMovesTheContent(): void {
        $v1 = $this->boot(new PersistenceV1Module());
        $v1->migrate();
        $car = new MigV1Car();
        $car->price = 150;
        $id = $v1->as(new SystemActor())->save($car);

        $v2 = $this->boot(new PersistenceV2Module());
        self::assertSame(PersistenceStatus::MigrationPending, $v2->status());

        $result = $v2->migrate();

        self::assertSame(['migtest:rename-price', 'migtest:cost-as-text', 'migtest:restructure'], $result->recorded);
        $loaded = $this->boot(new PersistenceV2Module())->as(new SystemActor())->load($id);
        self::assertInstanceOf(MigV2Car::class, $loaded);
        self::assertSame('150 EUR', $loaded->cost);
    }

    public function testCheckReportsWhatAMigrationWouldDoWithoutDoingIt(): void {
        $this->boot(new PersistenceV1Module())->migrate();

        $report = $this->boot(new PersistenceV2Module())->check();

        self::assertTrue($report->pending);
        self::assertFalse($report->isBlocked());
        self::assertSame(['migtest:rename-price', 'migtest:cost-as-text', 'migtest:restructure'], $report->pendingSteps);
        self::assertSame(PersistenceStatus::MigrationPending, $this->boot(new PersistenceV2Module())->status(), 'Nothing ran.');
    }

    public function testOnRequestThePendingMigrationRunsOnTheFirstCheck(): void {
        $persistence = $this->boot(new PersistenceV1Module(), MigrateOn::Request);

        self::assertSame(PersistenceStatus::Ready, $persistence->status());
        self::assertNotNull((new MigrationState($this->connection))->read()['stateKey']);
    }

    public function testTheCacheRemembersTheCodeButTheDatabaseStillDecides(): void {
        $this->boot(new PersistenceV1Module())->migrate();
        self::assertFileExists($this->cacheDirectory . '/coconut-cms-state.json');
        self::assertSame(PersistenceStatus::Ready, $this->boot(new PersistenceV1Module())->status());

        // Another server migrated the database elsewhere: the cache is still
        // valid here, but its key no longer matches.
        $this->connection->executeStatement(sprintf("UPDATE %s SET state_key = 'elsewhere'", MigrationState::TABLE));

        self::assertSame(PersistenceStatus::MigrationPending, $this->boot(new PersistenceV1Module())->status());
    }

    public function testEveryMigrationChangingSchemasRaisesTheSchemaRevision(): void {
        self::assertSame(0, (new MigrationState($this->connection))->revision(), 'Never migrated.');

        $this->boot(new PersistenceV1Module())->migrate();
        self::assertSame(1, $this->boot(new PersistenceV1Module())->as(new SystemActor())->schemaRevision());

        $this->boot(new PersistenceV1Module())->migrate();
        self::assertSame(1, $this->boot(new PersistenceV1Module())->as(new SystemActor())->schemaRevision(), 'Nothing changed.');

        $this->boot(new PersistenceV2Module())->migrate();
        self::assertSame(2, $this->boot(new PersistenceV2Module())->as(new SystemActor())->schemaRevision());
    }

    public function testAStateTableFromBeforeTheSchemaRevisionGainsIt(): void {
        $this->boot(new PersistenceV1Module())->migrate();
        $this->connection->executeStatement(sprintf('DROP TABLE %s', MigrationState::TABLE));
        $this->connection->executeStatement(sprintf('CREATE TABLE %s (id SMALLINT NOT NULL, state_key VARCHAR(64) DEFAULT NULL, migration_started_at DATETIME DEFAULT NULL, PRIMARY KEY (id))', MigrationState::TABLE));
        $this->connection->insert(MigrationState::TABLE, ['id' => 1, 'state_key' => 'old']);
        self::assertSame(0, (new MigrationState($this->connection))->revision(), 'No column yet.');

        $this->boot(new PersistenceV2Module())->migrate();

        self::assertSame(1, (new MigrationState($this->connection))->revision());
    }

    public function testAnUnfinishedMigrationRefusesEverything(): void {
        $this->boot(new PersistenceV1Module())->migrate();
        (new MigrationState($this->connection))->start();

        $persistence = $this->boot(new PersistenceV1Module());

        self::assertSame(PersistenceStatus::MigrationUnfinished, $persistence->status());
        $this->expectException(MigrationUnfinished::class);
        $persistence->as(new SystemActor())->load('0193a2b4-0000-7000-8000-000000000000');
    }
}
