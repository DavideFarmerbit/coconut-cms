<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Entity\Rows;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityTables;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRowsBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\Placement;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsCar;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsEngine;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsFixtures;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsMaker;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsTurbo;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsWheel;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

/** The rows EntityRowsBuilder describes fit the tables DatabaseSchema creates. */
final class EntityRowsInsertTest extends TestCase
{
    private Connection $connection;
    private InMemorySchemaStores $stores;
    /** @var list<LevelLayout> */
    private array $levels = [];

    protected function setUp(): void {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'user' => 'root',
            'password' => 'root',
            'unix_socket' => '/Users/davidebosco/Library/Application Support/Local/run/PzVL9Ueo_/mysql/mysqld.sock',
            'dbname' => 'local',
        ]);

        // Schemas compile in memory; only the content tables go to MySQL.
        $blueprints = NativeEntityReader::readAll([Cms::class, ...RowsFixtures::CLASSES]);
        $this->stores = new InMemorySchemaStores();
        $this->stores->compiler()->compile($blueprints);

        $layouts = new LayoutBuilder(new SchemaLookup($this->stores->registry));
        foreach (array_keys($blueprints) as $identifier) {
            $this->levels[] = $layouts->levelOf($identifier);
        }

        $this->dropTables();
        (new EntityTables($this->connection))->create($this->levels);
    }

    protected function tearDown(): void {
        $this->dropTables();
        $this->connection->close();
    }

    private function dropTables(): void {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (DatabaseSchema::of($this->levels)->getTables() as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table->getName()));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testEveryRowOfAFullyTouchedCarInsertsInTheOrderGiven(): void {
        $identities = new IdentityMap();
        $maker = new RowsMaker();
        $identities->remember($maker, EntityIds::generate());
        $this->connection->insert(DatabaseSchema::ENTITIES, ['id' => $identities->idOf($maker), 'concrete_identifier' => 'default:rows_maker']);

        $car = new RowsCar();
        $car->name = 'mine';
        $car->chassis->weight = 1200;
        $car->maker = $maker;
        $car->engine->horsepower = 450;
        $car->engine->turbo = new RowsTurbo();
        $car->spares = [new RowsEngine(), null];
        $car->wheels = [new RowsWheel()];
        $car->wheels[0]->maker = $maker;
        $car->tags = ['red'];

        $values = (new NativeEntityValues($this->stores->registry, $this->stores->values, $identities))->of($car);
        $all = (new EntityRowsBuilder(new SchemaLookup($this->stores->registry)))->rowsOf($values, EntityIds::generate(), Placement::standalone(), static fn (): string => EntityIds::generate());

        foreach ($all as $entity) {
            foreach ($entity->rows as $row) {
                $this->connection->insert($row->table, $row->values, $row->types());
            }
        }

        // The car, its engine, the engine's turbo, one spare, plus the maker and Cms.
        self::assertSame(6, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM entities'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rows_car__wheels__state WHERE maker = 1'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT spares_count FROM rows_car'));
    }
}
