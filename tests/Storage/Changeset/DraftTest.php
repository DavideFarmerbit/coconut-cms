<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Changeset;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Changeset\Changeset;
use XyloIsCoding\CoconutCms\Storage\Changeset\ChangesetFlusher;
use XyloIsCoding\CoconutCms\Storage\Changeset\DraftPreview;
use XyloIsCoding\CoconutCms\Storage\Changeset\EntityChange;
use XyloIsCoding\CoconutCms\Storage\Changeset\InMemoryDraftStore;
use XyloIsCoding\CoconutCms\Storage\Changeset\InMemoryUndoLog;
use XyloIsCoding\CoconutCms\Storage\Changeset\TempId;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Category;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\GalleryItem;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\RelatedProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Tag;

final class DraftTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private InMemoryDraftStore $draftStore;
    private DraftPreview $preview;
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
        $this->draftStore = new InMemoryDraftStore();
        $this->preview = new DraftPreview($this->entityManager);
        $this->flusher = new ChangesetFlusher($this->connection, $this->entityManager, new InMemoryUndoLog());
    }

    private function draftChangeset(): array
    {
        $tagId = $this->entityManager->repository(Tag::class)->insert(new Tag('sale'));
        $category = new TempId('cat');
        $product = new TempId('prod');

        $changeset = new Changeset([
            EntityChange::create($product, RelatedProduct::class, [
                'sku' => 'DRAFT-1',
                'category' => $category,
                'tags' => [$tagId],
                'gallery' => [new GalleryItem('first')],
            ]),
            EntityChange::create($category, Category::class, ['name' => 'Draft Category']),
        ]);

        return [$changeset, $product];
    }

    public function testSavingADraftWritesNothingToTheDatabase(): void
    {
        [$changeset] = $this->draftChangeset();

        $this->draftStore->save('prod', $changeset);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM products'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM categories'));
    }

    public function testPreviewResolvesATempIdReferenceToTheOtherChangesInProgressPreviewNotADatabaseRow(): void
    {
        [$changeset, $product] = $this->draftChangeset();
        $this->draftStore->save('prod', $changeset);

        $previewed = $this->preview->preview($this->draftStore->find('prod'), $product);

        self::assertSame('DRAFT-1', $previewed->sku);
        self::assertSame('Draft Category', $previewed->category->name);
        self::assertSame('sale', $previewed->tags[0]->name);
        self::assertSame('first', $previewed->gallery[0]->caption);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM categories'), 'previewing must not write anything, not even the temp-id-referenced entity');
    }

    public function testPreviewingAnUpdateMergesOnTopOfTheCurrentRowWithoutTouchingIt(): void
    {
        [$changeset, $product] = $this->draftChangeset();
        $result = $this->flusher->flush($changeset);
        $id = $result->resolvedIds[$product->id];

        $updateDraft = new Changeset([EntityChange::update($id, RelatedProduct::class, ['sku' => 'DRAFT-2'])]);

        $previewed = $this->preview->preview($updateDraft, $id);

        self::assertSame('DRAFT-2', $previewed->sku);
        self::assertSame('DRAFT-1', $this->entityManager->repository(RelatedProduct::class)->find($id)->sku, 'the real row must be untouched by a preview');
    }

    public function testPublishingADraftIsJustFlushingTheSameChangesetUnmodified(): void
    {
        [$changeset, $product] = $this->draftChangeset();
        $this->draftStore->save('prod', $changeset);

        $loaded = $this->draftStore->find('prod');
        $result = $this->flusher->flush($loaded);
        $this->draftStore->discard('prod');

        $published = $this->entityManager->repository(RelatedProduct::class)->find($result->resolvedIds[$product->id]);
        self::assertSame('DRAFT-1', $published->sku);
        self::assertSame('Draft Category', $published->category->name);
        self::assertNull($this->draftStore->find('prod'));
    }

    public function testSavingASecondDraftUnderTheSameKeyTakesOverTheFirst(): void
    {
        [$firstChangeset] = $this->draftChangeset();
        $this->draftStore->save('prod', $firstChangeset);

        $secondChangeset = new Changeset([EntityChange::create(new TempId('other'), Category::class, ['name' => 'Replaced'])]);
        $this->draftStore->save('prod', $secondChangeset);

        self::assertSame($secondChangeset, $this->draftStore->find('prod'));
    }
}
