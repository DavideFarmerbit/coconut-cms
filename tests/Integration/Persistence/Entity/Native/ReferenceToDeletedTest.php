<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Entity\Native;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityLoader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\Migrator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigDropEngine;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigDropV2Car;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigratorDroppedTarget;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** A save deleting an entity its own aggregate still points at. */
final class ReferenceToDeletedTest extends TestCase
{
    private Connection $connection;
    private SchemaRegistry $registry;
    private SchemaFieldValueStore $values;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        $this->dropEverything();

        $codec = new EntitySchemaCodec(new FieldValidatorRegistry(), new PrototypeValidatorRegistry(), new ValueObjectRegistry(), new EnumRegistry());
        $schemas = new SchemaStore($this->connection, $codec);
        $this->values = new SchemaFieldValueStore($this->connection);
        $this->registry = new SchemaRegistry($schemas);
        (new Migrator($this->connection, $this->registry, $schemas, $this->values))->migrate(NativeEntityReader::readAll(MigratorDroppedTarget::V2), MigrationSteps::none());
    }

    protected function tearDown(): void {
        $this->dropEverything();
        $this->connection->close();
    }

    private function dropEverything(): void {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE `%s`', $table));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testAReferenceToAnOwnedObjectTheSaveDeletesIsSavedNull(): void {
        $writer = new NativeEntityWriter(EntityWriter::of($this->connection, $this->registry), $this->registry, $this->values, new IdentityMap());
        $car = new MigDropV2Car();
        $car->spares = [new MigDropEngine(), new MigDropEngine()];
        $car->spares[1]->horsepower = 200;
        $id = $writer->save($car);
        $car->favorite = $car->spares[1];
        $writer->save($car);

        $car->spares = [$car->spares[0]];
        $writer->save($car);

        $classes = new NativeEntityRegistry();
        foreach (MigratorDroppedTarget::V2 as $class) {
            $classes->register($class);
        }
        $loaded = (new NativeEntityLoader(new EntityReader($this->connection, $this->registry, $this->values), $classes, new IdentityMap()))->load($id);

        self::assertNull($loaded->favorite);
        self::assertCount(1, $loaded->spares);
    }
}
