<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Changeset;

use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Changeset\EntityChangeKind;
use XyloIsCoding\CoconutCms\Storage\Changeset\EntityChangeRecord;
use XyloIsCoding\CoconutCms\Storage\Changeset\InMemoryUndoLog;

final class InMemoryUndoLogTest extends TestCase
{
    private static function record(string $entityId = '1', array $before = [], array $after = ['name' => 'x']): EntityChangeRecord
    {
        return new EntityChangeRecord(EntityChangeKind::Update, $entityId, 'Product', $before, $after);
    }

    public function testChangesSinceNullReturnsEveryRetainedRecord(): void
    {
        $log = new InMemoryUndoLog();
        $log->record([self::record()]);
        $log->record([self::record()]);

        self::assertCount(2, $log->changesSince(null));
    }

    public function testChangesSinceAnOperationExcludesThatOperationAndEverythingBeforeIt(): void
    {
        $log = new InMemoryUndoLog();
        $op1 = $log->record([self::record()]);
        $log->record([self::record()]);

        self::assertCount(1, $log->changesSince($op1->id));
    }

    public function testGetReturnsTheOperationWithThatId(): void
    {
        $log = new InMemoryUndoLog();
        $op = $log->record([self::record()]);

        self::assertSame($op, $log->get($op->id));
    }

    public function testUnknownOperationIdIsRejected(): void
    {
        $log = new InMemoryUndoLog();

        $this->expectException(LogicException::class);

        $log->changesSince('does-not-exist');
    }

    public function testRetentionKeepsOnlyTheLastNRecordsPerEntity(): void
    {
        $log = new InMemoryUndoLog(retainPerEntity: 2);

        $log->record([self::record(entityId: '1')]);
        $log->record([self::record(entityId: '1')]);
        $log->record([self::record(entityId: '1')]);

        self::assertCount(2, $log->changesSince(null));
    }

    public function testRetentionIsIndependentPerEntity(): void
    {
        $log = new InMemoryUndoLog(retainPerEntity: 1);

        $log->record([self::record(entityId: '1')]);
        $log->record([self::record(entityId: '2')]);

        self::assertCount(2, $log->changesSince(null), 'each entity gets its own retention budget, not a shared one');
    }
}
