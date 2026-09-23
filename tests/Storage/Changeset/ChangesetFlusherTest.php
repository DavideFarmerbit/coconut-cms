<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Changeset;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Changeset\Changeset;
use XyloIsCoding\CoconutCms\Storage\Changeset\ChangesetFlusher;
use XyloIsCoding\CoconutCms\Storage\Changeset\ConcurrentWriteException;
use XyloIsCoding\CoconutCms\Storage\Changeset\EntityChange;
use XyloIsCoding\CoconutCms\Storage\Changeset\InMemoryUndoLog;
use XyloIsCoding\CoconutCms\Storage\Changeset\TempId;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Storage\ValidationException;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Category;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\GalleryItem;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\RelatedProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Tag;

final class ChangesetFlusherTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private InMemoryUndoLog $undoLog;
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
        $this->undoLog = new InMemoryUndoLog();
        $this->flusher = new ChangesetFlusher($this->connection, $this->entityManager, $this->undoLog);
    }

    private function createProductChangeset(): array
    {
        $tagId = $this->entityManager->repository(Tag::class)->insert(new Tag('sale'));
        $category = new TempId('cat');
        $product = new TempId('prod');

        $changeset = new Changeset([
            EntityChange::create($product, RelatedProduct::class, [
                'sku' => 'SKU-1',
                'category' => $category,
                'tags' => [$tagId],
                'gallery' => [new GalleryItem('first')],
            ]),
            EntityChange::create($category, Category::class, ['name' => 'Widgets']),
        ]);

        return [$changeset, $tagId];
    }

    public function testMultiEntityCreateAndAttachFlushesAtomically(): void
    {
        [$changeset] = $this->createProductChangeset();

        $result = $this->flusher->flush($changeset);
        $productId = $result->resolvedIds['prod'];

        $product = $this->entityManager->repository(RelatedProduct::class)->find($productId);
        self::assertSame('SKU-1', $product->sku);
        self::assertSame('Widgets', $product->category->name);
        self::assertSame('sale', $product->tags[0]->name);
        self::assertSame('first', $product->gallery[0]->caption);
    }

    public function testInverseOfACreateOperationDeletesEverythingInReverseOrderButLeavesSharedReferencesAlone(): void
    {
        [$changeset, $tagId] = $this->createProductChangeset();
        $result = $this->flusher->flush($changeset);

        $inverse = $this->undoLog->get($result->operationId)->inverse();
        $this->flusher->flush($inverse);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM categories'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tags WHERE id = ?', [$tagId]), 'a pre-existing Shared reference is never part of the create it was attached from');
    }

    public function testInverseOfAnUpdateSwapsTheValueBack(): void
    {
        [$changeset] = $this->createProductChangeset();
        $created = $this->flusher->flush($changeset);
        $productId = $created->resolvedIds['prod'];

        $updated = $this->flusher->flush(new Changeset([
            EntityChange::update($productId, RelatedProduct::class, ['sku' => 'SKU-2'], $created->operationId),
        ]));

        $this->flusher->flush($this->undoLog->get($updated->operationId)->inverse());

        $product = $this->entityManager->repository(RelatedProduct::class)->find($productId);
        self::assertSame('SKU-1', $product->sku);
    }

    public function testInverseOfADeleteRestoresTheEntityWithItsOriginalId(): void
    {
        $tagId = $this->entityManager->repository(Tag::class)->insert(new Tag('sale'));

        $deleted = $this->flusher->flush(new Changeset([EntityChange::delete($tagId, Tag::class)]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tags'));

        $restored = $this->flusher->flush($this->undoLog->get($deleted->operationId)->inverse());

        self::assertSame([], $restored->resolvedIds, 'restore reuses the original id, nothing new to resolve');
        $tag = $this->entityManager->repository(Tag::class)->find($tagId);
        self::assertSame('sale', $tag->name);
    }

    public function testConcurrentWriteWithAValidReceiptSucceeds(): void
    {
        [$changeset] = $this->createProductChangeset();
        $created = $this->flusher->flush($changeset);
        $productId = $created->resolvedIds['prod'];

        $result = $this->flusher->flush(new Changeset([
            EntityChange::update($productId, RelatedProduct::class, ['sku' => 'SKU-2'], $created->operationId),
        ]));

        self::assertNotNull($result->operationId);
    }

    public function testConcurrentWriteWithAStaleReceiptIsRejected(): void
    {
        [$changeset] = $this->createProductChangeset();
        $created = $this->flusher->flush($changeset);
        $productId = $created->resolvedIds['prod'];

        $this->flusher->flush(new Changeset([
            EntityChange::update($productId, RelatedProduct::class, ['sku' => 'SKU-2'], $created->operationId),
        ]));

        $this->expectException(ConcurrentWriteException::class);

        $this->flusher->flush(new Changeset([
            EntityChange::update($productId, RelatedProduct::class, ['sku' => 'SKU-3'], $created->operationId),
        ]));
    }

    public function testDeleteConflictIsRejectedRegardlessOfWhichFieldChangedSince(): void
    {
        [$changeset] = $this->createProductChangeset();
        $created = $this->flusher->flush($changeset);
        $productId = $created->resolvedIds['prod'];

        // touches an unrelated field
        $this->flusher->flush(new Changeset([
            EntityChange::update($productId, RelatedProduct::class, ['sku' => 'SKU-2']),
        ]));

        $this->expectException(ConcurrentWriteException::class);

        $this->flusher->flush(new Changeset([
            EntityChange::delete($productId, RelatedProduct::class, $created->operationId),
        ]));
    }

    public function testDeletingAStillReferencedEntityFailsAtTheDatabaseLevel(): void
    {
        [$changeset] = $this->createProductChangeset();
        $this->flusher->flush($changeset);
        $categoryId = $this->connection->fetchOne('SELECT id FROM categories');

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->flusher->flush(new Changeset([EntityChange::delete($categoryId, Category::class)]));
    }

    public function testAFailedChangeRollsBackEverythingAlreadyAppliedInTheSameFlush(): void
    {
        $existing = $this->entityManager->repository(Tag::class)->insert(new Tag('sale'));

        try {
            $this->flusher->flush(new Changeset([
                EntityChange::create(new TempId('t'), Tag::class, ['name' => 'new tag']),
                EntityChange::update($existing, Tag::class, ['name' => 'renamed'], 'unknown-operation-id'),
            ]));
            self::fail('expected the stale-receipt update to reject the whole flush');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tags'), 'the first change must not have survived the rolled-back transaction');
    }

    public function testValidatorFailureIsRejectedThroughTheChangesetPathToo(): void
    {
        $this->expectException(ValidationException::class);

        $this->flusher->flush(new Changeset([
            EntityChange::create(new TempId('t'), Tag::class, ['name' => '']),
        ]));
    }
}
