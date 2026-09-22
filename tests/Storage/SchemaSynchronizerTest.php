<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;

final class SchemaSynchronizerTest extends TestCase
{
    public function testCreatesATableThatDoesNotExistYet(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $table = new Table('widgets');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        (new SchemaSynchronizer($connection))->sync($table);

        self::assertTrue($connection->createSchemaManager()->tablesExist(['widgets']));
    }

    public function testSyncingAnUnchangedTableTwiceIsANoOp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $table = new Table('widgets');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        $synchronizer = new SchemaSynchronizer($connection);
        $synchronizer->sync($table);
        $synchronizer->sync($table);

        self::assertTrue($connection->createSchemaManager()->tablesExist(['widgets']));
    }

    public function testAddingAColumnAltersAnExistingTableInPlace(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $synchronizer = new SchemaSynchronizer($connection);

        $before = new Table('widgets');
        $before->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $before->setPrimaryKey(['id']);
        $synchronizer->sync($before);

        $after = new Table('widgets');
        $after->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $after->addColumn('name', Types::STRING, ['notnull' => false]);
        $after->setPrimaryKey(['id']);
        $synchronizer->sync($after);

        $columns = array_map(
            static fn ($column) => $column->getName(),
            $connection->createSchemaManager()->listTableColumns('widgets'),
        );
        self::assertContains('name', $columns);
    }
}
