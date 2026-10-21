<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Entity\Layout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutCar;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutChassis;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutEngine;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutFixtures;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutMaker;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutPiston;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutVehicle;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutWheel;

final class DatabaseSchemaTest extends TestCase
{
    private Connection $connection;
    private Schema $schema;

    protected function setUp(): void {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'user' => 'root',
            'password' => 'root',
            'unix_socket' => '/Users/davidebosco/Library/Application Support/Local/run/PzVL9Ueo_/mysql/mysqld.sock',
            'dbname' => 'local',
        ]);

        $builder = LayoutFixtures::carBuilder();
        $levels = [];
        foreach ([LayoutMaker::class, LayoutPiston::class, LayoutEngine::class, LayoutWheel::class, LayoutChassis::class, LayoutVehicle::class, LayoutCar::class] as $class) {
            $levels[] = $builder->levelOf($class);
        }

        $this->schema = DatabaseSchema::of($levels);
        $this->dropTables();
    }

    protected function tearDown(): void {
        $this->dropTables();
        $this->connection->close();
    }

    /** Drops every table the schema has, whichever of them exist. */
    private function dropTables(): void {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->schema->getTables() as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table->getName()));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testMysqlAcceptsEveryTableAndCreatesThemAsDescribed(): void {
        $this->connection->createSchemaManager()->createSchemaObjects($this->schema);

        $schemaManager = $this->connection->createSchemaManager();
        foreach ($this->schema->getTables() as $table) {
            self::assertTrue($schemaManager->tablesExist([$table->getName()]), $table->getName());
        }

        $diff = $schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), $this->schema);
        foreach ($diff->getAlteredTables() as $altered) {
            self::assertTrue($altered->isEmpty(), sprintf('%s differs from what was created.', $altered->getOldTable()->getName()));
        }
    }
}
