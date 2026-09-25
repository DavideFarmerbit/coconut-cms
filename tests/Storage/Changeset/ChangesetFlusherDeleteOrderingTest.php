<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Changeset;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Changeset\Changeset;
use XyloIsCoding\CoconutCms\Storage\Changeset\ChangesetFlusher;
use XyloIsCoding\CoconutCms\Storage\Changeset\EntityChange;
use XyloIsCoding\CoconutCms\Storage\Changeset\InMemoryUndoLog;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Category;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\RelatedProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Tag;

/**
 * Proves the live-data half of delete ordering: a changeset deleting a referencer and
 * what it references, listed in the "wrong" order, succeeds by being silently
 * reordered instead of raising (and relying on) a RESTRICT constraint violation.
 */
final class ChangesetFlusherDeleteOrderingTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private ChangesetFlusher $flusher;

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
        $this->flusher = new ChangesetFlusher($this->connection, $this->entityManager, new InMemoryUndoLog());
    }

    private function buildReferencedPair(): array
    {
        $categoryId = $this->entityManager->repository(Category::class)->insert(new Category('Widgets'));
        $category = $this->entityManager->repository(Category::class)->find($categoryId);
        $productId = $this->entityManager->repository(RelatedProduct::class)->insert(new RelatedProduct('SKU-1', $category, [], []));

        return [$categoryId, $productId];
    }

    public function testDeletingTheReferencedEntityListedBeforeItsReferencerIsSilentlyReordered(): void
    {
        [$categoryId, $productId] = $this->buildReferencedPair();

        // deliberately "wrong" order: Category (referenced) listed before Product (referencer)
        $this->flusher->flush(new Changeset([
            EntityChange::delete($categoryId, Category::class),
            EntityChange::delete($productId, RelatedProduct::class),
        ]));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM categories'));
    }

    public function testDeletingInTheAlreadyCorrectOrderStillWorks(): void
    {
        [$categoryId, $productId] = $this->buildReferencedPair();

        $this->flusher->flush(new Changeset([
            EntityChange::delete($productId, RelatedProduct::class),
            EntityChange::delete($categoryId, Category::class),
        ]));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM categories'));
    }

    public function testDeletingOnlyTheReferencedEntityStillFailsCleanlyWhenItsReferencerIsNotPartOfTheSameChangeset(): void
    {
        [$categoryId] = $this->buildReferencedPair();

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);

        $this->flusher->flush(new Changeset([EntityChange::delete($categoryId, Category::class)]));
    }
}
