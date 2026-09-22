<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Storage\ValidationException;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Category;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\GalleryItem;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\RelatedProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Tag;

final class RepositoryRelationsTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $tables = [
            Category::class => 'categories',
            Tag::class => 'tags',
            RelatedProduct::class => 'products',
        ];

        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->syncAll([
            SchemaBuilder::tableFor('categories', PrototypeShape::ofClass(Category::class)),
            SchemaBuilder::tableFor('tags', PrototypeShape::ofClass(Tag::class)),
        ]);
        $synchronizer->syncAll(SchemaBuilder::tablesFor('products', PrototypeShape::ofClass(RelatedProduct::class), $tables));

        $this->entityManager = new EntityManager($this->connection, $tables);
    }

    private function buildProduct(string $sku = 'SKU-1'): RelatedProduct
    {
        $categoryId = $this->entityManager->repository(Category::class)->insert(new Category('Widgets'));
        $category = $this->entityManager->repository(Category::class)->find($categoryId);

        $tagId = $this->entityManager->repository(Tag::class)->insert(new Tag('sale-' . $sku));
        $tag = $this->entityManager->repository(Tag::class)->find($tagId);

        return new RelatedProduct($sku, $category, [$tag], [new GalleryItem('first'), new GalleryItem('second')]);
    }

    public function testSharedReferenceRoundTrips(): void
    {
        $id = $this->entityManager->repository(RelatedProduct::class)->insert($this->buildProduct());

        $found = $this->entityManager->repository(RelatedProduct::class)->find($id);

        self::assertSame('Widgets', $found->category->name);
    }

    public function testSharedCollectionRoundTrips(): void
    {
        $id = $this->entityManager->repository(RelatedProduct::class)->insert($this->buildProduct('SKU-2'));

        $found = $this->entityManager->repository(RelatedProduct::class)->find($id);

        self::assertCount(1, $found->tags);
        self::assertSame('sale-SKU-2', $found->tags[0]->name);
    }

    public function testOwnedCollectionRoundTrips(): void
    {
        $id = $this->entityManager->repository(RelatedProduct::class)->insert($this->buildProduct());

        $found = $this->entityManager->repository(RelatedProduct::class)->find($id);

        self::assertSame(['first', 'second'], array_map(static fn (GalleryItem $item): string => $item->caption, $found->gallery));
    }

    public function testFindReusesTheSameReferencedInstanceThroughTheSharedIdentityMap(): void
    {
        $product = $this->buildProduct();
        $id = $this->entityManager->repository(RelatedProduct::class)->insert($product);

        $found = $this->entityManager->repository(RelatedProduct::class)->find($id);

        self::assertSame($product->category, $found->category, 'the category was already in the identity map from buildProduct()');
    }

    public function testDeletingASharedReferenceTargetIsRestrictedWhileStillReferenced(): void
    {
        $product = $this->buildProduct();
        $this->entityManager->repository(RelatedProduct::class)->insert($product);
        $categoryId = $this->connection->fetchOne('SELECT id FROM categories');

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection->delete('categories', ['id' => $categoryId]);
    }

    public function testDeletingTheOwnerCascadesOwnedChildrenButLeavesSharedTargetsAlone(): void
    {
        $product = $this->buildProduct();
        $id = $this->entityManager->repository(RelatedProduct::class)->insert($product);
        $tagId = $this->connection->fetchOne('SELECT id FROM tags');

        $this->entityManager->repository(RelatedProduct::class)->delete($id);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products_gallery'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products_tags'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tags WHERE id = ?', [$tagId]));
    }

    public function testInsertRejectsAReferenceToAnUnpersistedEntity(): void
    {
        $this->expectException(ValidationException::class);

        $this->entityManager->repository(RelatedProduct::class)->insert(
            new RelatedProduct('SKU-3', new Category('Unpersisted'), [], []),
        );
    }

    public function testUpdateReplacesTheWholeCollectionSet(): void
    {
        $id = $this->entityManager->repository(RelatedProduct::class)->insert($this->buildProduct());

        $updated = $this->entityManager->repository(RelatedProduct::class)->update($id, [
            'gallery' => [new GalleryItem('only one now')],
        ]);

        self::assertSame(['only one now'], array_map(static fn (GalleryItem $item): string => $item->caption, $updated->gallery));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products_gallery'));
    }
}
