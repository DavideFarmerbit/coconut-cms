<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Values;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

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
        $this->store->save('Engine', FieldValues::fromArray(['horsepower' => 300]));
        $this->store->load('Engine');

        $this->writeBehindTheStoresBack('Engine', 'horsepower', 999);

        // Not reloaded: the first load is remembered.
        self::assertSame(['horsepower' => 300], $this->store->load('Engine')->toArray());
    }

    public function testSaveForgetsWhatItOverwrites(): void {
        $this->store->save('Engine', FieldValues::fromArray(['horsepower' => 300]));
        $this->store->load('Engine');

        $this->store->save('Engine', FieldValues::fromArray(['horsepower' => 500]));

        self::assertSame(['horsepower' => 500], $this->store->load('Engine')->toArray());
    }

    public function testSaveAllForgetsOnlyWhatItOverwrites(): void {
        $this->store->saveAll(['Engine' => FieldValues::fromArray(['horsepower' => 300]), 'Piston' => FieldValues::fromArray(['diameter' => 10])]);
        $this->store->load('Engine');
        $this->store->load('Piston');
        $this->writeBehindTheStoresBack('Piston', 'diameter', 99);

        $this->store->saveAll(['Engine' => FieldValues::fromArray(['horsepower' => 500])]);

        self::assertSame(['horsepower' => 500], $this->store->load('Engine')->toArray());
        self::assertSame(['diameter' => 10], $this->store->load('Piston')->toArray());
    }

    public function testAnIdentifierWithNothingStoredIsRememberedToo(): void {
        self::assertSame([], $this->store->load('Engine')->toArray());

        $this->connection->insert('schema_field_values', ['identifier' => 'Engine', 'field_name' => 'horsepower', 'value_json' => '300']);

        self::assertSame([], $this->store->load('Engine')->toArray());
    }
}
