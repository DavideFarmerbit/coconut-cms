<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\EntityRegistrar;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\Prototype\PrototypeRegistry;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Address;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\BaseProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\DigitalProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\PremiumDigitalProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Product;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar\CollisionA;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar\CollisionB;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar\NamedTable;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Registrar\RenamedOrder;

/**
 * Phase 6.2: registering a native class (or chain) is one call, no hand-built
 * class => table array, no separately invoked SchemaBuilder/SchemaSynchronizer calls.
 */
final class EntityRegistrarTest extends TestCase
{
    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        return $connection;
    }

    public function testAStandaloneClassGetsATableDerivedFromItsShortClassName(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Product::class]);

        $columns = array_keys($connection->createSchemaManager()->listTableColumns('product'));
        self::assertSame(['id', 'sku', 'name', 'address_city', 'active', 'data'], $columns);

        $id = $entityManager->repository(Product::class)->insert(
            new Product('SKU-1', 'Widget', 'A fine widget', new Address('Rome', 'Via Roma 1'), true),
        );

        self::assertSame('Widget', $entityManager->repository(Product::class)->find($id)->name);
    }

    public function testAnExplicitTableNameAttributeOverridesTheDerivedFallback(): void
    {
        $connection = $this->connection();
        EntityRegistrar::register($connection, [NamedTable::class]);

        $tableNames = array_map(
            static fn ($table) => $table->getName(),
            $connection->createSchemaManager()->listTables(),
        );

        self::assertContains('custom_products', $tableNames);
        self::assertNotContains('namedtable', $tableNames);
    }

    public function testListingOnlyTheLeafOfAChainStillRegistersEveryLevel(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [PremiumDigitalProduct::class]);

        $tableNames = array_map(
            static fn ($table) => $table->getName(),
            $connection->createSchemaManager()->listTables(),
        );
        self::assertContains('baseproduct', $tableNames);
        self::assertContains('digitalproduct', $tableNames);
        self::assertContains('premiumdigitalproduct', $tableNames);

        $id = $entityManager->repository(PremiumDigitalProduct::class)->insert(
            new PremiumDigitalProduct('SKU-2', 'Ebook', 'https://example.test/file', 3),
        );
        $found = $entityManager->repository(PremiumDigitalProduct::class)->find($id);

        self::assertSame('SKU-2', $found->sku);
        self::assertSame(3, $found->bonusContentCount);

        // The middle level of the same chain is independently usable too, same
        // guarantee RepositoryInheritanceTest already proves for a hand-built map.
        $digitalId = $entityManager->repository(DigitalProduct::class)->insert(
            new DigitalProduct('SKU-3', 'Plain digital', 'https://example.test/file2'),
        );
        self::assertSame('Plain digital', $entityManager->repository(BaseProduct::class)->find($digitalId)->name);
    }

    public function testTwoClassesThatDeriveToTheSameTableNameFailRegistrationLoudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(CollisionA\Order::class);

        EntityRegistrar::register($this->connection(), [CollisionA\Order::class, CollisionB\Order::class]);
    }

    public function testRenameMovesTheOldTableToTheNewClassesDerivedNameAndFixesReferences(): void
    {
        $connection = $this->connection();
        $synchronizer = new SchemaSynchronizer($connection);
        $synchronizer->syncAll(PrototypeRegistry::schemaTables());
        $synchronizer->sync(SchemaBuilder::tableFor('legacy_orders', PrototypeShape::ofClass(RenamedOrder::class)));
        $connection->insert('legacy_orders', ['reference' => 'REF-1', 'data' => '{}']);

        $registry = new PrototypeRegistry($connection);
        $registry->define('SpecialOrder', 'XyloIsCoding\\Legacy\\FruitOrder');

        EntityRegistrar::rename($connection, $registry, 'XyloIsCoding\\Legacy\\FruitOrder', 'legacy_orders', RenamedOrder::class);

        $tableNames = $connection->createSchemaManager()->listTableNames();
        self::assertContains('renamedorder', $tableNames);
        self::assertNotContains('legacy_orders', $tableNames);
        self::assertSame('REF-1', $connection->fetchOne('SELECT reference FROM renamedorder'));
        self::assertSame(RenamedOrder::class, $registry->parentOf('SpecialOrder'));
    }

    public function testRenameIsANoOpWhenTheNewClassDerivesTheSameTableNameAsBefore(): void
    {
        $connection = $this->connection();
        $synchronizer = new SchemaSynchronizer($connection);
        $synchronizer->syncAll(PrototypeRegistry::schemaTables());
        $synchronizer->sync(SchemaBuilder::tableFor('custom_products', PrototypeShape::ofClass(NamedTable::class)));

        $registry = new PrototypeRegistry($connection);

        EntityRegistrar::rename($connection, $registry, 'XyloIsCoding\\Legacy\\OldNamedTable', 'custom_products', NamedTable::class);

        self::assertContains('custom_products', $connection->createSchemaManager()->listTableNames());
    }
}
