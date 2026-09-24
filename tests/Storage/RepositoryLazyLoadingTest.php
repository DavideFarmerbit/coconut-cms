<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Category;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\GalleryItem;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\RelatedProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Tag;

final class RepositoryLazyLoadingTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $tables = [Category::class => 'categories', Tag::class => 'tags', RelatedProduct::class => 'products'];

        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->syncAll([
            SchemaBuilder::tableFor('categories', PrototypeShape::ofClass(Category::class)),
            SchemaBuilder::tableFor('tags', PrototypeShape::ofClass(Tag::class)),
        ]);
        $synchronizer->syncAll(SchemaBuilder::tablesFor('products', PrototypeShape::ofClass(RelatedProduct::class), $tables));

        $this->entityManager = new EntityManager($this->connection, $tables);
    }

    private function buildProduct(string $sku): string
    {
        $categoryId = $this->entityManager->repository(Category::class)->insert(new Category('Widgets'));
        $category = $this->entityManager->repository(Category::class)->find($categoryId);

        $tagId = $this->entityManager->repository(Tag::class)->insert(new Tag('sale'));
        $tag = $this->entityManager->repository(Tag::class)->find($tagId);

        return $this->entityManager->repository(RelatedProduct::class)->insert(
            new RelatedProduct($sku, $category, [$tag], [new GalleryItem('first')]),
        );
    }

    public function testASharedReferenceIsNotResolvedUntilItsOwnPropertyIsTouched(): void
    {
        $id = $this->buildProduct('SKU-1');

        // a fresh repository/identity map, so find() below can't be reusing an already-hydrated instance
        $entityManager = new EntityManager($this->connection, [
            Category::class => 'categories',
            Tag::class => 'tags',
            RelatedProduct::class => 'products',
        ]);

        $product = $entityManager->repository(RelatedProduct::class)->find($id);
        $categoryReflection = new ReflectionClass(Category::class);

        self::assertTrue($categoryReflection->isUninitializedLazyObject($product->category), 'must not be resolved just by loading the product');

        self::assertSame('Widgets', $product->category->name);

        self::assertFalse($categoryReflection->isUninitializedLazyObject($product->category), 'touching the property must trigger it');
    }

    public function testASharedCollectionItemIsNotResolvedUntilItsOwnPropertyIsTouched(): void
    {
        $id = $this->buildProduct('SKU-2');

        $entityManager = new EntityManager($this->connection, [
            Category::class => 'categories',
            Tag::class => 'tags',
            RelatedProduct::class => 'products',
        ]);

        $product = $entityManager->repository(RelatedProduct::class)->find($id);
        $tagReflection = new ReflectionClass(Tag::class);

        self::assertTrue($tagReflection->isUninitializedLazyObject($product->tags[0]));
        self::assertSame('sale', $product->tags[0]->name);
        self::assertFalse($tagReflection->isUninitializedLazyObject($product->tags[0]));
    }

    public function testTwoOwnersReferencingTheSameEntityShareTheExactSameInstanceEvenBeforeTouching(): void
    {
        $categoryId = $this->entityManager->repository(Category::class)->insert(new Category('Shared'));
        $category = $this->entityManager->repository(Category::class)->find($categoryId);

        $productRepo = $this->entityManager->repository(RelatedProduct::class);
        $id1 = $productRepo->insert(new RelatedProduct('SKU-A', $category, [], []));
        $id2 = $productRepo->insert(new RelatedProduct('SKU-B', $category, [], []));

        $entityManager = new EntityManager($this->connection, [
            Category::class => 'categories',
            Tag::class => 'tags',
            RelatedProduct::class => 'products',
        ]);
        $freshRepo = $entityManager->repository(RelatedProduct::class);

        $product1 = $freshRepo->find($id1);
        $product2 = $freshRepo->find($id2);

        self::assertSame($product1->category, $product2->category, 'same underlying category id must resolve to the same object, lazy or not');
    }

    public function testALazyReferenceOnceTouchedIsTheSameInstanceADirectFindReturns(): void
    {
        $categoryId = $this->entityManager->repository(Category::class)->insert(new Category('Direct'));
        $category = $this->entityManager->repository(Category::class)->find($categoryId);
        $productId = $this->entityManager->repository(RelatedProduct::class)->insert(new RelatedProduct('SKU-C', $category, [], []));

        $entityManager = new EntityManager($this->connection, [
            Category::class => 'categories',
            Tag::class => 'tags',
            RelatedProduct::class => 'products',
        ]);

        $product = $entityManager->repository(RelatedProduct::class)->find($productId);
        $touchedName = $product->category->name; // triggers the ghost

        $direct = $entityManager->repository(Category::class)->find($categoryId);

        self::assertSame($direct, $product->category);
        self::assertSame('Direct', $touchedName);
    }
}
