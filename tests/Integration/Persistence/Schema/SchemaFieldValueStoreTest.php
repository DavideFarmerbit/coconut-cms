<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\ColumnFlattener;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaFieldValueStore;

#[Entity]
final class ValueStorePiston
{
    #[Scalar(ScalarType::Int)]
    private int $diameter = 42;

    #[Scalar(ScalarType::Int)]
    private int $stroke = 10;
}

#[Entity]
final class ValueStoreEngineWithPiston
{
    #[Embed(ValueStorePiston::class)]
    private object $piston;

    public function __construct() {
        $this->piston = new ValueStorePiston();
    }
}

#[Entity]
final class ValueStoreChassis
{
    #[Embed(ValueStoreEngineWithPiston::class)]
    private object $engine;

    public function __construct() {
        $this->engine = new ValueStoreEngineWithPiston();
    }
}

#[Entity]
final class ValueStoreCarThreeDeepEmbed
{
    #[Embed(ValueStoreChassis::class)]
    private object $chassis;

    public function __construct() {
        $this->chassis = new ValueStoreChassis();
    }
}

final class SchemaFieldValueStoreTest extends TestCase
{
    private Connection $connection;
    private SchemaFieldValueStore $store;

    protected function setUp(): void {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'user' => 'root',
            'password' => 'root',
            'unix_socket' => '/Users/davidebosco/Library/Application Support/Local/run/PzVL9Ueo_/mysql/mysqld.sock',
            'dbname' => 'local',
        ]);

        $this->store = new SchemaFieldValueStore($this->connection);
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
        $blueprint = NativeEntityReader::read(ValueStoreCarThreeDeepEmbed::class);
        $flattener = new ColumnFlattener(static function (string $target): FieldDescriptorCollection {
            $targetBlueprint = NativeEntityReader::read($target);

            return $targetBlueprint->fields;
        });
        $columns = $flattener->flatten($blueprint->fields, $blueprint->defaultValues)['columns'];

        self::assertSame(
            ['chassis_engine_piston_diameter' => 42, 'chassis_engine_piston_stroke' => 10],
            $columns,
        );

        $this->store->save(ValueStoreCarThreeDeepEmbed::class, $columns);

        $withOnlyDiameterChanged = [
            'chassis_engine_piston_diameter' => 43,
            'chassis_engine_piston_stroke' => 10,
        ];

        // Only the column that actually changed is reported: the sibling leaf,
        // stroke, at the exact same nesting depth, correctly stays untouched.
        // FieldName has no identity semantics, so this compares by value.
        self::assertEquals(
            ['added' => [], 'removed' => [], 'changed' => [FieldName::of('chassis_engine_piston_diameter')]],
            $this->store->diff(ValueStoreCarThreeDeepEmbed::class, $withOnlyDiameterChanged),
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

        self::assertEquals(
            ['added' => [], 'removed' => [], 'changed' => [FieldName::of('horsepower')]],
            $this->store->diff('App\\Engine', ['horsepower' => 100]),
        );
    }

    public function testDiffReportsANewlyAddedField(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300]);

        self::assertEquals(
            ['added' => [FieldName::of('torque')], 'removed' => [], 'changed' => []],
            $this->store->diff('App\\Engine', ['horsepower' => 300, 'torque' => 400]),
        );
    }

    public function testDiffReportsARemovedField(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300, 'torque' => 400]);

        self::assertEquals(
            ['added' => [], 'removed' => [FieldName::of('torque')], 'changed' => []],
            $this->store->diff('App\\Engine', ['horsepower' => 300]),
        );
    }

    public function testDiffOnAnIdentifierNeverSavedTreatsEveryFieldAsAdded(): void {
        self::assertEquals(
            ['added' => [FieldName::of('horsepower')], 'removed' => [], 'changed' => []],
            $this->store->diff('App\\NeverSaved', ['horsepower' => 300]),
        );
    }

    public function testResolveReturnsTheMostSpecificScopesExplicitValue(): void {
        $this->store->save('SpecialEngine', ['horsepower' => 999]);
        $this->store->save('Engine', ['horsepower' => 300]);

        self::assertSame(999, $this->store->resolve(['SpecialEngine', 'Engine'], FieldName::of('horsepower')));
    }

    public function testResolveFallsThroughToALessSpecificScopeWhenTheMostSpecificHasNoValue(): void {
        $this->store->save('Engine', ['horsepower' => 300]);

        // "QuietEngine" was never saved at all; nothing but the fallback chain
        // itself says it should defer to "Engine".
        self::assertSame(300, $this->store->resolve(['QuietEngine', 'Engine'], FieldName::of('horsepower')));
    }

    public function testResolveKeepsAnExplicitValueIndependentOfWhatALessSpecificScopeLaterReports(): void {
        $this->store->save('SpecialEngine', ['horsepower' => 100]);
        $this->store->save('Engine', ['horsepower' => 100]);

        self::assertSame(100, $this->store->resolve(['SpecialEngine', 'Engine'], FieldName::of('horsepower')));

        // Engine's own value changes; SpecialEngine's own explicit row is
        // untouched by that, because it was never a fallback to begin with.
        $this->store->save('Engine', ['horsepower' => 999]);

        self::assertSame(100, $this->store->resolve(['SpecialEngine', 'Engine'], FieldName::of('horsepower')));
    }

    public function testResolveSupportsAnOwningFieldSiteFallingThroughToItsTargetType(): void {
        $this->store->save('Engine', ['horsepower' => 100]);

        self::assertSame(100, $this->store->resolve(['AudiCar.engine', 'Engine'], FieldName::of('horsepower')));
    }

    public function testResolveWithNoScopeHavingAValueThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->store->resolve(['Unknown'], FieldName::of('horsepower'));
    }
}
