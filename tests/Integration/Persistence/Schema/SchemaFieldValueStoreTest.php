<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaFieldValueStore;

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

    public function testDiffPinpointsExactlyWhichKeyChangedBehindAnOwnedReference(): void {
        $this->store->save('App\\Car', [
            'engine' => 'App\\Engine',
            'engine.horsepower' => 500,
            'engine.piston:diameter' => 10,
        ]);

        // Only the key that actually changed is reported: the sibling leaf
        // behind the same owned reference stays untouched. FieldPath has no
        // identity semantics, so this compares by value.
        self::assertEquals(
            ['added' => [], 'removed' => [], 'changed' => [FieldPath::fromString('engine.piston:diameter')]],
            $this->store->diff('App\\Car', [
                'engine' => 'App\\Engine',
                'engine.horsepower' => 500,
                'engine.piston:diameter' => 11,
            ]),
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
            ['added' => [], 'removed' => [], 'changed' => [FieldPath::fromString('horsepower')]],
            $this->store->diff('App\\Engine', ['horsepower' => 100]),
        );
    }

    public function testDiffReportsANewlyAddedField(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300]);

        self::assertEquals(
            ['added' => [FieldPath::fromString('torque')], 'removed' => [], 'changed' => []],
            $this->store->diff('App\\Engine', ['horsepower' => 300, 'torque' => 400]),
        );
    }

    public function testDiffReportsARemovedField(): void {
        $this->store->save('App\\Engine', ['horsepower' => 300, 'torque' => 400]);

        self::assertEquals(
            ['added' => [], 'removed' => [FieldPath::fromString('torque')], 'changed' => []],
            $this->store->diff('App\\Engine', ['horsepower' => 300]),
        );
    }

    public function testDiffOnAnIdentifierNeverSavedTreatsEveryFieldAsAdded(): void {
        self::assertEquals(
            ['added' => [FieldPath::fromString('horsepower')], 'removed' => [], 'changed' => []],
            $this->store->diff('App\\NeverSaved', ['horsepower' => 300]),
        );
    }

    public function testResolveReturnsTheMostSpecificScopesExplicitValue(): void {
        $this->store->save('SpecialEngine', ['horsepower' => 999]);
        $this->store->save('Engine', ['horsepower' => 300]);

        self::assertSame(999, $this->store->resolve(['SpecialEngine', 'Engine'], FieldPath::fromString('horsepower')));
    }

    public function testResolveFallsThroughToALessSpecificScopeWhenTheMostSpecificHasNoValue(): void {
        $this->store->save('Engine', ['horsepower' => 300]);

        // "QuietEngine" was never saved at all; nothing but the fallback chain
        // itself says it should defer to "Engine".
        self::assertSame(300, $this->store->resolve(['QuietEngine', 'Engine'], FieldPath::fromString('horsepower')));
    }

    public function testResolveKeepsAnExplicitValueIndependentOfWhatALessSpecificScopeLaterReports(): void {
        $this->store->save('SpecialEngine', ['horsepower' => 100]);
        $this->store->save('Engine', ['horsepower' => 100]);

        self::assertSame(100, $this->store->resolve(['SpecialEngine', 'Engine'], FieldPath::fromString('horsepower')));

        // Engine's own value changes; SpecialEngine's own explicit row is
        // untouched by that, because it was never a fallback to begin with.
        $this->store->save('Engine', ['horsepower' => 999]);

        self::assertSame(100, $this->store->resolve(['SpecialEngine', 'Engine'], FieldPath::fromString('horsepower')));
    }

    public function testResolveSupportsAnOwningFieldSiteFallingThroughToItsTargetType(): void {
        $this->store->save('Engine', ['horsepower' => 100]);

        self::assertSame(100, $this->store->resolve(['AudiCar.engine', 'Engine'], FieldPath::fromString('horsepower')));
    }

    public function testResolveWithNoScopeHavingAValueThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->store->resolve(['Unknown'], FieldPath::fromString('horsepower'));
    }
}
