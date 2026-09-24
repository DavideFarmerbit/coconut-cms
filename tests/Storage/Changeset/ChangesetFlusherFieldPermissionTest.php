<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Changeset;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Changeset\Changeset;
use XyloIsCoding\CoconutCms\Storage\Changeset\ChangesetFlusher;
use XyloIsCoding\CoconutCms\Storage\Changeset\EntityChange;
use XyloIsCoding\CoconutCms\Storage\Changeset\InMemoryUndoLog;
use XyloIsCoding\CoconutCms\Storage\Changeset\TempId;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\Permission\FieldPermissionDenied;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Permission\RestrictedNote;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype\SimpleActor;

final class ChangesetFlusherFieldPermissionTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private InMemoryUndoLog $undoLog;
    private ChangesetFlusher $flusher;
    private SimpleActor $editor;
    private SimpleActor $outsider;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        (new SchemaSynchronizer($this->connection))->sync(SchemaBuilder::tableFor('notes', PrototypeShape::ofClass(RestrictedNote::class)));

        $this->entityManager = new EntityManager($this->connection, [RestrictedNote::class => 'notes']);
        $this->undoLog = new InMemoryUndoLog();
        $this->flusher = new ChangesetFlusher($this->connection, $this->entityManager, $this->undoLog);

        $this->editor = new SimpleActor(['editor']);
        $this->outsider = new SimpleActor([]);
    }

    private function createChangeset(string $body): Changeset
    {
        return new Changeset([
            EntityChange::create(new TempId('note'), RestrictedNote::class, ['title' => 'Title', 'body' => $body]),
        ]);
    }

    public function testFlushWithoutAnActorSkipsFieldPermissionCheckingEntirely(): void
    {
        $result = $this->flusher->flush($this->createChangeset('anything'));

        self::assertNotNull($result->operationId);
    }

    public function testCreateTouchingARestrictedFieldIsRejectedForAnUnauthorizedActor(): void
    {
        $this->expectException(FieldPermissionDenied::class);

        $this->flusher->flush($this->createChangeset('secret'), $this->outsider);
    }

    public function testCreateRejectionHappensBeforeAnyWrite(): void
    {
        try {
            $this->flusher->flush($this->createChangeset('secret'), $this->outsider);
        } catch (FieldPermissionDenied) {
            // expected
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM notes'));
    }

    public function testCreateSucceedsForAnAuthorizedActor(): void
    {
        $result = $this->flusher->flush($this->createChangeset('secret'), $this->editor);

        $note = $this->entityManager->repository(RestrictedNote::class)->find($result->resolvedIds['note']);
        self::assertSame('secret', $note->body);
    }

    public function testUpdatingAnUnrestrictedFieldDoesNotRequirePermissionForTheRestrictedOne(): void
    {
        $created = $this->flusher->flush($this->createChangeset('secret'), $this->editor);
        $id = $created->resolvedIds['note'];

        $result = $this->flusher->flush(
            new Changeset([EntityChange::update($id, RestrictedNote::class, ['title' => 'Renamed'])]),
            $this->outsider,
        );

        self::assertNotNull($result->operationId);
    }

    public function testUpdatingTheRestrictedFieldIsRejectedForAnUnauthorizedActor(): void
    {
        $created = $this->flusher->flush($this->createChangeset('secret'), $this->editor);
        $id = $created->resolvedIds['note'];

        $this->expectException(FieldPermissionDenied::class);

        $this->flusher->flush(
            new Changeset([EntityChange::update($id, RestrictedNote::class, ['body' => 'leaked'])]),
            $this->outsider,
        );
    }

    /**
     * ARCHITECTURE.md's claim: this generalizes to revision-restore for free, with zero
     * special-casing. Restoring is just "build and flush a changeset" (here: the
     * computed inverse of a prior update), so it goes through the exact same
     * write-permission check as any other flush.
     */
    public function testFlushingAnInverseChangesetTouchingARestrictedFieldIsRejectedTheSameWay(): void
    {
        $created = $this->flusher->flush($this->createChangeset('original'), $this->editor);
        $id = $created->resolvedIds['note'];

        $updated = $this->flusher->flush(
            new Changeset([EntityChange::update($id, RestrictedNote::class, ['body' => 'changed'])]),
            $this->editor,
        );

        $inverse = $this->undoLog->get($updated->operationId)->inverse();

        $this->expectException(FieldPermissionDenied::class);

        $this->flusher->flush($inverse, $this->outsider);
    }
}
