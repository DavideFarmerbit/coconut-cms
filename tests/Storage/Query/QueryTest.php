<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\DynamicEntity;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldKind;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\Schema\InMemorySchemaUndoLog;
use XyloIsCoding\CoconutCms\Storage\Schema\PrototypeRegistry;
use XyloIsCoding\CoconutCms\Storage\Schema\SchemaEditor;
use XyloIsCoding\CoconutCms\Storage\Query\Cursor;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Address;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\BaseProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\DigitalProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Inheritance\PremiumDigitalProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Product;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Schema\ExtensibleProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Schema\SimpleActor;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Category;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\RelatedProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Tag;

/**
 * Phase 7, Step B: Query filters/sorts/cursor-paginates one identifier's own chain,
 * native or editor-created, through one multi-table JOIN instead of Repository's
 * one-query-per-level approach.
 */
final class QueryTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;

    /** @var array<string, string> */
    private const array TABLES = [
        BaseProduct::class => 'base_products',
        DigitalProduct::class => 'digital_products',
        PremiumDigitalProduct::class => 'premium_digital_products',
    ];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->sync(SchemaBuilder::entitiesTable());
        $synchronizer->syncAll(
            SchemaBuilder::tablesForChain(PrototypeShape::chainOfClass(PremiumDigitalProduct::class), self::TABLES),
        );

        $this->entityManager = new EntityManager($this->connection, self::TABLES);
    }

    /** @return string[] ids, in insertion order (SKU-1..SKU-5), bonusContentCount 10,30,20,50,40 */
    private function seedFiveProducts(): array
    {
        $repository = $this->entityManager->repository(PremiumDigitalProduct::class);
        $counts = [10, 30, 20, 50, 40];

        return array_map(
            static fn (int $index): string => $repository->insert(new PremiumDigitalProduct(
                sprintf('SKU-%d', $index + 1),
                sprintf('Ebook %d', $index + 1),
                'https://example.test/file',
                $counts[$index],
            )),
            array_keys($counts),
        );
    }

    public function testFiltersByAFieldDeclaredOnTheBaseLevel(): void
    {
        $this->seedFiveProducts();

        $page = $this->entityManager->query(PremiumDigitalProduct::class)->where('sku', '=', 'SKU-3')->get();

        self::assertCount(1, $page->items);
        self::assertSame('SKU-3', $page->items[0]->sku);
    }

    public function testFiltersAndSortsByAFieldDeclaredOnTheLeafLevel(): void
    {
        $this->seedFiveProducts();

        $page = $this->entityManager->query(PremiumDigitalProduct::class)
            ->where('bonusContentCount', '>', 15)
            ->orderBy('bonusContentCount')
            ->get();

        self::assertSame([20, 30, 40, 50], array_map(static fn (PremiumDigitalProduct $p): int => $p->bonusContentCount, $page->items));
    }

    public function testCursorPaginationReturnsEveryRowExactlyOnceInSortOrder(): void
    {
        $this->seedFiveProducts();

        $seen = [];
        $cursor = null;
        do {
            $query = $this->entityManager->query(PremiumDigitalProduct::class)->orderBy('bonusContentCount')->limit(2);
            if ($cursor !== null) {
                $query->after($cursor);
            }

            $page = $query->get();
            foreach ($page->items as $item) {
                $seen[] = $item->bonusContentCount;
            }

            $cursor = $page->nextCursor;
        } while ($page->hasMore);

        self::assertSame([10, 20, 30, 40, 50], $seen);
        self::assertNull($cursor);
    }

    public function testACursorRoundTripsThroughEncodeDecode(): void
    {
        $this->seedFiveProducts();

        $firstPage = $this->entityManager->query(PremiumDigitalProduct::class)->orderBy('bonusContentCount')->limit(2)->get();
        self::assertTrue($firstPage->hasMore);

        $decoded = Cursor::decode($firstPage->nextCursor->encode());
        $secondPage = $this->entityManager->query(PremiumDigitalProduct::class)->orderBy('bonusContentCount')->limit(2)->after($decoded)->get();

        self::assertSame([30, 40], array_map(static fn (PremiumDigitalProduct $p): int => $p->bonusContentCount, $secondPage->items));
    }

    public function testCountMatchesTheFilterIndependentOfLimit(): void
    {
        $this->seedFiveProducts();

        $query = $this->entityManager->query(PremiumDigitalProduct::class)->where('bonusContentCount', '>', 15);

        self::assertSame(4, $query->count());
        self::assertCount(2, $query->limit(2)->get()->items);
    }

    public function testAnUnsupportedOperatorIsRejected(): void
    {
        $this->expectException(LogicException::class);

        $this->entityManager->query(PremiumDigitalProduct::class)->where('sku', 'LIKE', 'SKU%');
    }

    public function testFilteringByANonQueryableBlobOnlyFieldIsRejected(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $synchronizer = new SchemaSynchronizer($connection);
        $synchronizer->sync(SchemaBuilder::entitiesTable());
        $synchronizer->sync(SchemaBuilder::tableFor('products', PrototypeShape::ofClass(Product::class)));
        $entityManager = new EntityManager($connection, [Product::class => 'products']);

        $this->expectException(LogicException::class);

        $entityManager->query(Product::class)->where('description', '=', 'anything')->get();
    }

    public function testABoolFieldFiltersAndSortsCorrectly(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $synchronizer = new SchemaSynchronizer($connection);
        $synchronizer->sync(SchemaBuilder::entitiesTable());
        $synchronizer->sync(SchemaBuilder::tableFor('products', PrototypeShape::ofClass(Product::class)));
        $entityManager = new EntityManager($connection, [Product::class => 'products']);
        $repository = $entityManager->repository(Product::class);

        $repository->insert(new Product('SKU-A', 'Active one', '', new Address('Rome', 'Via Roma 1'), true));
        $repository->insert(new Product('SKU-B', 'Inactive one', '', new Address('Rome', 'Via Roma 1'), false));

        $page = $entityManager->query(Product::class)->where('active', '=', true)->get();

        self::assertCount(1, $page->items);
        self::assertSame('SKU-A', $page->items[0]->sku);
    }

    public function testAnEntityReferenceFieldCanBeFilteredByItsIdColumn(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $tables = [Category::class => 'categories', Tag::class => 'tags', RelatedProduct::class => 'products'];

        $synchronizer = new SchemaSynchronizer($connection);
        $synchronizer->sync(SchemaBuilder::entitiesTable());
        $synchronizer->syncAll([
            SchemaBuilder::tableFor('categories', PrototypeShape::ofClass(Category::class)),
            SchemaBuilder::tableFor('tags', PrototypeShape::ofClass(Tag::class)),
        ]);
        $synchronizer->syncAll(SchemaBuilder::tablesFor('products', PrototypeShape::ofClass(RelatedProduct::class), $tables));

        $entityManager = new EntityManager($connection, $tables);
        $categoryId = $entityManager->repository(Category::class)->insert(new Category('Widgets'));
        $category = $entityManager->repository(Category::class)->find($categoryId);
        $otherCategoryId = $entityManager->repository(Category::class)->insert(new Category('Gadgets'));
        $other = $entityManager->repository(Category::class)->find($otherCategoryId);

        $entityManager->repository(RelatedProduct::class)->insert(new RelatedProduct('SKU-1', $category, [], []));
        $entityManager->repository(RelatedProduct::class)->insert(new RelatedProduct('SKU-2', $other, [], []));

        $page = $entityManager->query(RelatedProduct::class)->where('category', '=', $categoryId)->get();

        self::assertCount(1, $page->items);
        self::assertSame('SKU-1', $page->items[0]->sku);
    }

    public function testFiltersByAFieldDeclaredOnADerivedEditorCreatedLevel(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $tables = [ExtensibleProduct::class => 'extensible_products'];

        $synchronizer = new SchemaSynchronizer($connection);
        $synchronizer->syncAll(PrototypeRegistry::schemaTables());
        $synchronizer->sync(SchemaBuilder::entitiesTable());
        $synchronizer->sync(SchemaBuilder::tableFor('extensible_products', PrototypeShape::ofClass(ExtensibleProduct::class)));

        $registry = new PrototypeRegistry($connection);
        $editor = new SchemaEditor($connection, $registry, $synchronizer, new InMemorySchemaUndoLog(), $tables);
        $manager = new SimpleActor(['store-manager']);

        $editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true),
        ], $manager);
        $tables['Electronics'] = $editor->tableOf('Electronics');

        $entityManager = new EntityManager($connection, $tables, $registry);
        $repository = $entityManager->repository('Electronics');
        $repository->insert(new DynamicEntity('Electronics', ['sku' => 'E-1', 'voltage' => 110]));
        $repository->insert(new DynamicEntity('Electronics', ['sku' => 'E-2', 'voltage' => 240]));

        $page = $entityManager->query('Electronics')->where('voltage', '>', 200)->get();

        self::assertCount(1, $page->items);
        self::assertSame('E-2', $page->items[0]->get('sku'));
    }
}
