<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaFieldValueStore;

final class SchemaFieldValueStoreCacheTest extends TestCase
{
    private Connection $connection;
    private SchemaFieldValueStore $store;

    protected function setUp(): void {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->store = new SchemaFieldValueStore($this->connection);
        $this->store->createTable();
    }

    /** Changes a stored value without going through the store at all. */
    private function writeBehindTheStoresBack(string $identifier, string $field, mixed $value): void {
        $this->connection->update('schema_field_values', ['value_json' => json_encode($value)], ['identifier' => $identifier, 'field_name' => $field]);
    }

    public function testLoadRemembersWhatItLoaded(): void {
        $this->store->save('Engine', ['horsepower' => 300]);
        $this->store->load('Engine');

        $this->writeBehindTheStoresBack('Engine', 'horsepower', 999);

        // Not reloaded: the first load is remembered.
        self::assertSame(['horsepower' => 300], $this->store->load('Engine'));
    }

    public function testSaveForgetsWhatItOverwrites(): void {
        $this->store->save('Engine', ['horsepower' => 300]);
        $this->store->load('Engine');

        $this->store->save('Engine', ['horsepower' => 500]);

        self::assertSame(['horsepower' => 500], $this->store->load('Engine'));
    }

    public function testSaveAllForgetsOnlyWhatItOverwrites(): void {
        $this->store->saveAll(['Engine' => ['horsepower' => 300], 'Piston' => ['diameter' => 10]]);
        $this->store->load('Engine');
        $this->store->load('Piston');
        $this->writeBehindTheStoresBack('Piston', 'diameter', 99);

        $this->store->saveAll(['Engine' => ['horsepower' => 500]]);

        self::assertSame(['horsepower' => 500], $this->store->load('Engine'));
        self::assertSame(['diameter' => 10], $this->store->load('Piston'));
    }

    public function testAnIdentifierWithNothingStoredIsRememberedToo(): void {
        self::assertSame([], $this->store->load('Engine'));

        $this->connection->insert('schema_field_values', ['identifier' => 'Engine', 'field_name' => 'horsepower', 'value_json' => '300']);

        self::assertSame([], $this->store->load('Engine'));
    }
}
