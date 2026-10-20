<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldValuesDiff;
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
        $this->store->save('App\\Engine', FieldValues::fromArray(['horsepower' => 300, 'name' => 'V8']));

        self::assertSame(['horsepower' => 300, 'name' => 'V8'], $this->store->load('App\\Engine')->toArray());
    }

    public function testDiffPinpointsExactlyWhichKeyChangedBehindAnOwnedReference(): void {
        $this->store->save('App\\Car', FieldValues::fromArray([
            'engine' => 'App\\Engine',
            'engine.horsepower' => 500,
            'engine.piston:diameter' => 10,
        ]));

        // Only the key that actually changed is reported: the sibling leaf
        // behind the same owned reference stays untouched. FieldPath has no
        // identity semantics, so this compares by value.
        self::assertEquals(
            new FieldValuesDiff([], [], [FieldPath::fromString('engine.piston:diameter')]),
            $this->store->diff('App\\Car', FieldValues::fromArray([
                'engine' => 'App\\Engine',
                'engine.horsepower' => 500,
                'engine.piston:diameter' => 11,
            ])),
        );
    }

    public function testSaveReplacesWhateverWasThereBefore(): void {
        $this->store->save('App\\Engine', FieldValues::fromArray(['horsepower' => 300]));
        $this->store->save('App\\Engine', FieldValues::fromArray(['torque' => 400]));

        self::assertSame(['torque' => 400], $this->store->load('App\\Engine')->toArray());
    }

    public function testDiffReportsNoChangesWhenCurrentMatchesTheStoredSnapshot(): void {
        $this->store->save('App\\Engine', FieldValues::fromArray(['horsepower' => 300]));

        self::assertEquals(
            new FieldValuesDiff([], [], []),
            $this->store->diff('App\\Engine', FieldValues::fromArray(['horsepower' => 300])),
        );
    }

    public function testDiffReportsAChangedFieldWhenItsDefaultValueChanged(): void {
        $this->store->save('App\\Engine', FieldValues::fromArray(['horsepower' => 300]));

        self::assertEquals(
            new FieldValuesDiff([], [], [FieldPath::fromString('horsepower')]),
            $this->store->diff('App\\Engine', FieldValues::fromArray(['horsepower' => 100])),
        );
    }

    public function testDiffReportsANewlyAddedField(): void {
        $this->store->save('App\\Engine', FieldValues::fromArray(['horsepower' => 300]));

        self::assertEquals(
            new FieldValuesDiff([FieldPath::fromString('torque')], [], []),
            $this->store->diff('App\\Engine', FieldValues::fromArray(['horsepower' => 300, 'torque' => 400])),
        );
    }

    public function testDiffReportsARemovedField(): void {
        $this->store->save('App\\Engine', FieldValues::fromArray(['horsepower' => 300, 'torque' => 400]));

        self::assertEquals(
            new FieldValuesDiff([], [FieldPath::fromString('torque')], []),
            $this->store->diff('App\\Engine', FieldValues::fromArray(['horsepower' => 300])),
        );
    }

    public function testDiffOnAnIdentifierNeverSavedTreatsEveryFieldAsAdded(): void {
        self::assertEquals(
            new FieldValuesDiff([FieldPath::fromString('horsepower')], [], []),
            $this->store->diff('App\\NeverSaved', FieldValues::fromArray(['horsepower' => 300])),
        );
    }
}
