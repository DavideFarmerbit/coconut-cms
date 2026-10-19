<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntityRegistrar;
use XyloIsCoding\CoconutCms\Persistence\Schema\NativeSchemaColumnFlattener;
use XyloIsCoding\CoconutCms\Persistence\Schema\NativeSchemaSnapshotStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\ScalarType;

#[Entity]
final class SnapshotStorePiston
{
    #[Scalar(ScalarType::Int)]
    private int $diameter = 42;

    #[Scalar(ScalarType::Int)]
    private int $stroke = 10;
}

#[Entity]
final class SnapshotStoreEngineWithPiston
{
    #[Embed(SnapshotStorePiston::class)]
    private object $piston;

    public function __construct() {
        $this->piston = new SnapshotStorePiston();
    }
}

#[Entity]
final class SnapshotStoreChassis
{
    #[Embed(SnapshotStoreEngineWithPiston::class)]
    private object $engine;

    public function __construct() {
        $this->engine = new SnapshotStoreEngineWithPiston();
    }
}

#[Entity]
final class SnapshotStoreCarThreeDeepEmbed
{
    #[Embed(SnapshotStoreChassis::class)]
    private object $chassis;

    public function __construct() {
        $this->chassis = new SnapshotStoreChassis();
    }
}

final class NativeSchemaSnapshotStoreTest extends TestCase
{
    private Connection $connection;
    private NativeSchemaSnapshotStore $store;

    protected function setUp(): void {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'user' => 'root',
            'password' => 'root',
            'unix_socket' => '/Users/davidebosco/Library/Application Support/Local/run/PzVL9Ueo_/mysql/mysqld.sock',
            'dbname' => 'local',
        ]);

        $this->store = new NativeSchemaSnapshotStore($this->connection);
        $this->store->dropTableIfExists();
        $this->store->createTable();
    }

    protected function tearDown(): void {
        $this->store->dropTableIfExists();
        $this->connection->close();
    }

    public function testSaveThenLoadRoundTripsPlainScalarValues(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300, 'name' => 'V8']);

        self::assertSame(['horsepower' => 300, 'name' => 'V8'], $this->store->load('App\\Engine'));
    }

    public function testSaveThenLoadRoundTripsNestedValues(): void {
        $this->store->save('App\\Car', [
            'engine' => ['horsepower' => 300],
            'owner' => null,
        ]);

        self::assertSame(
            ['engine' => ['horsepower' => 300], 'owner' => null],
            $this->store->load('App\\Car'),
        );
    }

    public function testSaveThenLoadRoundTripsEmbedNestedInsideEmbedInsideEmbed(): void {
        $threeDeepEmbed = [
            'chassis' => [
                'engine' => [
                    'piston' => ['diameter' => 42],
                ],
            ],
        ];

        $this->store->save('App\\Car', $threeDeepEmbed);

        self::assertSame($threeDeepEmbed, $this->store->load('App\\Car'));
    }

    public function testSaveThenLoadRoundTripsAnOwningReferencesFieldsContainingAnEmbed(): void {
        $ownedContainingEmbed = [
            'owner' => [
                'class' => 'App\\Person',
                'fields' => [
                    'home' => ['city' => 'Rome'],
                ],
            ],
        ];

        $this->store->save('App\\Car', $ownedContainingEmbed);

        self::assertSame($ownedContainingEmbed, $this->store->load('App\\Car'));
    }

    public function testSaveThenLoadRoundTripsAnEmbedContainingAnOwningReference(): void {
        $embedContainingOwned = [
            'chassis' => [
                'battery' => [
                    'class' => 'App\\LithiumBattery',
                    'fields' => ['capacity' => 75],
                ],
            ],
        ];

        $this->store->save('App\\Car', $embedContainingOwned);

        self::assertSame($embedContainingOwned, $this->store->load('App\\Car'));
    }

    public function testDiffOnFlattenedColumnsPinpointsExactlyWhichColumnChangedThreeLevelsDeep(): void {
        $schema = EntityRegistrar::register(SnapshotStoreCarThreeDeepEmbed::class);
        $columns = NativeSchemaColumnFlattener::flatten($schema->fields, $schema->defaultValues)['columns'];

        self::assertSame(
            ['chassis_engine_piston_diameter' => 42, 'chassis_engine_piston_stroke' => 10],
            $columns,
        );

        $this->store->save(SnapshotStoreCarThreeDeepEmbed::class, $columns);

        $withOnlyDiameterChanged = [
            'chassis_engine_piston_diameter' => 43,
            'chassis_engine_piston_stroke' => 10,
        ];

        // Only the column that actually changed is reported: the sibling leaf,
        // stroke, at the exact same nesting depth, correctly stays untouched.
        self::assertSame(
            ['added' => [], 'removed' => [], 'changed' => ['chassis_engine_piston_diameter']],
            $this->store->diff(SnapshotStoreCarThreeDeepEmbed::class, $withOnlyDiameterChanged),
        );
    }

    public function testSaveReplacesWhateverWasThereBefore(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300]);
        $this->store->save('App\\Engine', ['torque' => 400]);

        self::assertSame(['torque' => 400], $this->store->load('App\\Engine'));
    }

    public function testDiffReportsNoChangesWhenCurrentMatchesTheStoredSnapshot(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300]);

        self::assertSame(
            ['added' => [], 'removed' => [], 'changed' => []],
            $this->store->diff('App\\Engine', ['horsepower' => 300]),
        );
    }

    public function testDiffReportsAChangedFieldWhenItsDefaultValueChanged(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300]);

        self::assertSame(
            ['added' => [], 'removed' => [], 'changed' => ['horsepower']],
            $this->store->diff('App\\Engine', ['horsepower' => 100]),
        );
    }

    public function testDiffReportsANewlyAddedField(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300]);

        self::assertSame(
            ['added' => ['torque'], 'removed' => [], 'changed' => []],
            $this->store->diff('App\\Engine', ['horsepower' => 300, 'torque' => 400]),
        );
    }

    public function testDiffReportsARemovedField(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300, 'torque' => 400]);

        self::assertSame(
            ['added' => [], 'removed' => ['torque'], 'changed' => []],
            $this->store->diff('App\\Engine', ['horsepower' => 300]),
        );
    }

    public function testDiffOnAnIdentifierNeverSavedTreatsEveryFieldAsAdded(): void {
        self::assertSame(
            ['added' => ['horsepower'], 'removed' => [], 'changed' => []],
            $this->store->diff('App\\NeverSaved', ['horsepower' => 300]),
        );
    }
}
