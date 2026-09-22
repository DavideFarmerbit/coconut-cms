<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\BaseProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\DigitalProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\PremiumDigitalProduct;

final class RepositoryInheritanceTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;

    /** @var array<class-string, string> */
    private const array TABLES = [
        BaseProduct::class => 'base_products',
        DigitalProduct::class => 'digital_products',
        PremiumDigitalProduct::class => 'premium_digital_products',
    ];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        (new SchemaSynchronizer($this->connection))->syncAll(
            SchemaBuilder::tablesForChain(PrototypeShape::chainOfClass(PremiumDigitalProduct::class), self::TABLES),
        );

        $this->entityManager = new EntityManager($this->connection, self::TABLES);
    }

    public function testEachLevelGetsItsOwnTableHoldingOnlyItsOwnFields(): void
    {
        $manager = $this->connection->createSchemaManager();

        $baseColumns = array_keys($manager->listTableColumns('base_products'));
        $digitalColumns = array_keys($manager->listTableColumns('digital_products'));

        self::assertSame(['id', 'sku', 'name', 'data'], $baseColumns);
        self::assertSame(['id', 'downloadurl', 'data'], $digitalColumns);
    }

    public function testInsertWritesTheSameIdToEveryLevelsTable(): void
    {
        $id = $this->entityManager->repository(PremiumDigitalProduct::class)->insert(
            new PremiumDigitalProduct('SKU-1', 'Ebook', 'https://example.test/file', 3),
        );

        self::assertSame($id, (string) $this->connection->fetchOne('SELECT id FROM base_products'));
        self::assertSame($id, (string) $this->connection->fetchOne('SELECT id FROM digital_products'));
        self::assertSame($id, (string) $this->connection->fetchOne('SELECT id FROM premium_digital_products'));
    }

    public function testFindReconstructsFieldsFromEveryLevelOfTheChain(): void
    {
        $id = $this->entityManager->repository(PremiumDigitalProduct::class)->insert(
            new PremiumDigitalProduct('SKU-2', 'Ebook 2', 'https://example.test/file2', 5),
        );

        $found = $this->entityManager->repository(PremiumDigitalProduct::class)->find($id);

        self::assertSame('SKU-2', $found->sku);
        self::assertSame('Ebook 2', $found->name);
        self::assertSame('https://example.test/file2', $found->downloadUrl);
        self::assertSame(5, $found->bonusContentCount);
    }

    public function testUpdateChangesFieldsAcrossDifferentLevels(): void
    {
        $repository = $this->entityManager->repository(PremiumDigitalProduct::class);
        $id = $repository->insert(new PremiumDigitalProduct('SKU-3', 'Ebook 3', 'https://example.test/file3', 1));

        $updated = $repository->update($id, ['name' => 'Renamed', 'bonusContentCount' => 9]);

        self::assertSame('Renamed', $updated->name);
        self::assertSame(9, $updated->bonusContentCount);
        self::assertSame('https://example.test/file3', $updated->downloadUrl, 'untouched fields on other levels must survive');
    }

    public function testDeletingTheLeafCascadesEveryLevelDownToTheBase(): void
    {
        $id = $this->entityManager->repository(PremiumDigitalProduct::class)->insert(
            new PremiumDigitalProduct('SKU-4', 'Ebook 4', 'https://example.test/file4', 0),
        );

        $this->entityManager->repository(PremiumDigitalProduct::class)->delete($id);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM base_products'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM digital_products'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM premium_digital_products'));
    }

    public function testAMiddleLevelPrototypeIsStillUsableOnItsOwnAsAChainOfTwo(): void
    {
        $id = $this->entityManager->repository(DigitalProduct::class)->insert(
            new DigitalProduct('SKU-5', 'Plain digital', 'https://example.test/file5'),
        );

        $found = $this->entityManager->repository(DigitalProduct::class)->find($id);

        self::assertSame('Plain digital', $found->name);
        self::assertSame('https://example.test/file5', $found->downloadUrl);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM premium_digital_products'));
    }
}
