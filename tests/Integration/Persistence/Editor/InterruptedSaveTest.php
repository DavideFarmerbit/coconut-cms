<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Editor;

use RuntimeException;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\MigrateOn;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceStatus;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicModule;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicTestCase;
use XyloIsCoding\CoconutCms\Tests\Integration\StoredColumns;

/** Passes a save's dry pass, and fails inside the move: what no check can see coming. */
final readonly class ConverterFailingInTheMove implements FieldConverter
{
    public static function from(): FieldSignature {
        return NumberToText::from();
    }

    public static function to(): FieldSignature {
        return NumberToText::to();
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['function'] ?? '') === 'moveAggregates') {
                throw new RuntimeException('It fails in the move.');
            }
        }

        return (new NumberToText())->convert($old, $to);
    }
}

/**
 * A migration stopped part way: by a failure the migration sees, or by a
 * crash it doesn't. Whichever, no stored value is lost.
 */
final class InterruptedSaveTest extends DynamicTestCase
{
    private function persistence(): Persistence {
        return Persistence::boot($this->connection, [new DynamicModule()], null, MigrateOn::Command, new StrictPolicy());
    }

    /** @return list<string> The columns of the yacht's table moved aside. */
    private function movedAside(): array {
        $columns = [];
        foreach ($this->connection->createSchemaManager()->introspectTable('dy_yacht')->getColumns() as $column) {
            if (str_starts_with($column->getName(), '_old__')) {
                $columns[] = $column->getName();
            }
        }

        return $columns;
    }

    /** What expand leaves when a crash stops the move: cabins moved aside, a new one empty beside it. */
    private function crashAfterExpand(): void {
        $cabins = (new StoredColumns($this->connection))->column('dy_yacht', 'cabins');
        $this->connection->executeStatement(sprintf('ALTER TABLE dy_yacht RENAME COLUMN %1$s TO _old__%1$s', $cabins));
        $this->connection->executeStatement(sprintf('ALTER TABLE dy_yacht ADD COLUMN %s VARCHAR(255) NULL', $cabins));
        (new MigrationState($this->connection))->start();
    }

    public function testASaveFailingInTheMoveLeavesEverythingAsItWas(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);
        $persistence = $this->persistence();

        try {
            $persistence->saveSchemas(new EditorSave(1, [new RetypeField('dy:yacht', FieldDescriptor::scalar('cabins', ScalarType::String, Nullability::NotNullable), new ConverterFailingInTheMove())]), new SystemActor());
            self::fail('The save should fail.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('It fails in the move.', $failure->getMessage());
        }

        self::assertNull((new MigrationState($this->connection))->read()['startedAt'], 'The tables are back: the site keeps running.');
        self::assertSame([], $this->movedAside());
        self::assertSame(1, (new MigrationState($this->connection))->revision(), 'Nothing was saved.');
        self::assertSame(PersistenceStatus::Ready, $this->persistence()->status());
        self::assertSame(5, $this->session()->loadDynamic($yacht)->get('cabins'));
    }

    public function testASaveFailingInTheMoveCanBeSentAgainOnceFixed(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);
        $retype = static fn (FieldConverter $converter): EditorSave => new EditorSave(1, [new RetypeField('dy:yacht', FieldDescriptor::scalar('cabins', ScalarType::String, Nullability::NotNullable), $converter)]);

        try {
            $this->persistence()->saveSchemas($retype(new ConverterFailingInTheMove()), new SystemActor());
            self::fail('The save should fail.');
        } catch (RuntimeException) {
        }

        $this->persistence()->saveSchemas($retype(new NumberToText()), new SystemActor());

        self::assertSame('5', $this->session()->loadDynamic($yacht)->get('cabins'));
    }

    public function testMigratingAfterACrashBeforeTheCommitKeepsTheValues(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);
        $revision = (new MigrationState($this->connection))->revision();
        $this->crashAfterExpand();

        $this->persistence()->migrate();

        self::assertNull((new MigrationState($this->connection))->read()['startedAt']);
        self::assertSame([], $this->movedAside());
        self::assertSame($revision, (new MigrationState($this->connection))->revision(), 'What crashed never saved.');
        self::assertSame(5, $this->session()->loadDynamic($yacht)->get('cabins'));
    }

    public function testMigratingAfterACrashPastTheCommitFinishesTheJob(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);
        $revision = (new MigrationState($this->connection))->revision();

        // The move committed, and the crash came before contract.
        $this->connection->executeStatement('ALTER TABLE dy_yacht ADD COLUMN _old__length INTEGER NULL');
        $state = new MigrationState($this->connection);
        $state->start();
        $state->commit();

        $this->persistence()->migrate();

        self::assertNull((new MigrationState($this->connection))->read()['startedAt']);
        self::assertSame([], $this->movedAside(), 'What the move left behind is gone.');
        self::assertSame($revision + 1, (new MigrationState($this->connection))->revision(), 'The save it finished counts.');
        self::assertSame(5, $this->session()->loadDynamic($yacht)->get('cabins'));
    }
}
