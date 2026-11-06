<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValuesSnapshot;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\Platform\Platforms;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicModule;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicTestCase;
use XyloIsCoding\CoconutCms\Tests\Integration\ReadsStoredColumns;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/**
 * A prototype, not the feature: retyping dy:yacht.cabins from int to text by
 * expand, backfill, switch and contract, written in plain SQL. The real writer
 * and reader play the old release, which knows nothing about any of it.
 *
 * The new values live in their own column, cabins__v2, and a marker column,
 * _moved__v2, says a row was converted. Any save deletes and inserts its row
 * with the columns its code knows, so an old release clears the marker without
 * knowing it exists.
 */
final class RetypeSpike
{
    use ReadsStoredColumns;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function expand(): void {
        $this->connection->executeStatement('ALTER TABLE dy_yacht ADD COLUMN cabins__v2 VARCHAR(255) NULL');
        $this->connection->executeStatement('ALTER TABLE dy_yacht ADD COLUMN _moved__v2 SMALLINT NULL');
    }

    /** Converts up to $size rows not converted yet, each in its own short transaction; gives how many it took. */
    public function backfill(int $size): int {
        $ids = $this->connection->fetchFirstColumn(sprintf('SELECT _id FROM dy_yacht WHERE _moved__v2 IS NULL ORDER BY _id LIMIT %d', $size));
        foreach ($ids as $id) {
            $this->convert($id);
        }

        return count($ids);
    }

    /** Converts one row, holding the lock a save takes, so no save lands between the read and the write. */
    public function convert(string $id): void {
        $this->connection->transactional(function () use ($id): void {
            Platforms::of($this->connection)->lockEntity($this->connection, $id);
            $row = $this->connection->fetchAssociative($this->sql('SELECT {dy_yacht.cabins} AS cabins, _moved__v2 FROM dy_yacht WHERE _id = ?'), [$id]);
            if ($row !== false && $row['_moved__v2'] === null) {
                $this->connection->executeStatement('UPDATE dy_yacht SET cabins__v2 = ?, _moved__v2 = 1 WHERE _id = ?', [self::up($row['cabins']), $id]);
            }
        });
    }

    /** The converter, as the backfill and a load both run it. */
    private static function up(mixed $old): mixed {
        $descriptor = FieldDescriptor::scalar('cabins', ScalarType::String, Nullability::NotNullable);

        return (new NumberToText())->convert(new Captured((int) $old, false, new ValuesSnapshot([], [])), $descriptor)->value();
    }

    /** What the new release reads: the new column, or the old one through the converter for a row left behind. */
    public function read(string $id): mixed {
        $row = $this->connection->fetchAssociative($this->sql('SELECT {dy_yacht.cabins} AS cabins, cabins__v2, _moved__v2 FROM dy_yacht WHERE _id = ?'), [$id]);

        return $row['_moved__v2'] === null ? self::up($row['cabins']) : $row['cabins__v2'];
    }

    /** @return list<string> What a query on the new field finds. */
    public function queryNew(string $value): array {
        return $this->connection->fetchFirstColumn('SELECT _id FROM dy_yacht WHERE cabins__v2 = ?', [$value]);
    }

    public function remaining(): int {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dy_yacht WHERE _moved__v2 IS NULL');
    }

    public function contract(): void {
        $this->connection->executeStatement('ALTER TABLE dy_yacht DROP COLUMN _moved__v2');
        $this->connection->executeStatement($this->sql('ALTER TABLE dy_yacht DROP COLUMN {dy_yacht.cabins}'));
    }
}

/** What the retype prototype has to prove, against the real writer and reader. */
final class RetypeSpikeTest extends DynamicTestCase
{
    use ReadsStoredColumns;

    /** A save by the old release: the real session and writer, on the schema still stored. */
    private function oldReleaseSaves(string $id, int $cabins): void {
        $session = $this->session();
        $yacht = $session->loadDynamic($id);
        $yacht->set('cabins', $cabins);
        $session->save($yacht);
    }

    public function testTheOldReleaseKeepsReadingAndWritingThroughTheBackfill(): void {
        $first = $this->store('dy:yacht', ['cabins' => 5]);
        $second = $this->store('dy:yacht', ['cabins' => 6]);
        $spike = new RetypeSpike($this->connection);

        $spike->expand();
        $spike->backfill(1);

        self::assertSame(5, $this->session()->loadDynamic($first)->get('cabins'), 'Old columns stay filled.');
        self::assertSame(6, $this->session()->loadDynamic($second)->get('cabins'));
        $this->oldReleaseSaves($second, 7);
        self::assertSame(7, $this->session()->loadDynamic($second)->get('cabins'));
    }

    public function testAnOldReleaseSaveClearsTheMarkerWithoutKnowingIt(): void {
        $id = $this->store('dy:yacht', ['cabins' => 5]);
        $spike = new RetypeSpike($this->connection);
        $spike->expand();
        $spike->backfill(10);
        self::assertSame(0, $spike->remaining());
        self::assertSame('5', $spike->read($id));

        $this->oldReleaseSaves($id, 9);

        self::assertSame(1, $spike->remaining(), 'The old release cleared what it never heard of.');
        self::assertSame('9', $spike->read($id), 'Read through the converter, never the stale column.');
        $spike->backfill(10);
        self::assertSame(0, $spike->remaining());
        self::assertSame('9', $spike->read($id));
    }

    public function testAReferenceAndAnOwnedObjectSurviveTheSaveThatClearsTheMarker(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5, 'dinghy' => 'dy:motor', 'dinghy.horsepower' => 40]);
        $craft = $this->store('dy:craft', ['name' => 'Tug', 'tow' => $yacht]);
        $dinghies = $this->ownedIds($yacht, 'dinghy');
        self::assertCount(1, $dinghies);
        $spike = new RetypeSpike($this->connection);
        $spike->expand();
        $spike->backfill(10);

        $this->oldReleaseSaves($yacht, 9);

        self::assertSame(1, $spike->remaining(), 'The save rewrote the row.');
        self::assertSame($yacht, $this->stored('dy_craft', 'tow', $craft), 'What points at the entity points at its entities row, which stays.');
        self::assertSame($dinghies, $this->ownedIds($yacht, 'dinghy'), 'What it owns keeps its id.');
        self::assertSame(40, $this->session()->loadDynamic($yacht)->get('dinghy')->get('horsepower'));
    }

    public function testAnEntityTheOldReleaseCreatesIsPickedUp(): void {
        $spike = new RetypeSpike($this->connection);
        $spike->expand();
        $spike->backfill(10);

        $id = $this->store('dy:yacht', ['cabins' => 4]);

        self::assertSame(1, $spike->remaining());
        self::assertSame('4', $spike->read($id));
    }

    public function testAQueryOnTheNewFieldMissesARowLeftBehindUntilTheNextScan(): void {
        $id = $this->store('dy:yacht', ['cabins' => 5]);
        $spike = new RetypeSpike($this->connection);
        $spike->expand();
        $spike->backfill(10);

        $this->oldReleaseSaves($id, 9);

        self::assertSame([], $spike->queryNew('9'), 'The known gap: empty, never the old value.');
        self::assertSame([], $spike->queryNew('5'), 'It never matches the value it had.');
        $spike->backfill(10);
        self::assertSame([$id], $spike->queryNew('9'));
    }

    public function testASaveInFlightAfterTheSwitchIsCaughtAndCorruptsNothing(): void {
        $id = $this->store('dy:yacht', ['cabins' => 5]);
        $spike = new RetypeSpike($this->connection);
        $spike->expand();
        $spike->backfill(10);

        // The new release reads cabins__v2 from here on; the old column stays
        // under its own name, so an old save can't write into the new one.
        $this->oldReleaseSaves($id, 12);

        self::assertSame('12', $spike->read($id));
        $spike->backfill(10);
        self::assertSame(0, $spike->remaining());
        $spike->contract();
        self::assertSame('12', $this->connection->fetchOne('SELECT cabins__v2 FROM dy_yacht WHERE _id = ?', [$id]));
    }

    /** Adds a Collection of numbers to the buoy, through a real editor save, and gives a buoy holding $numbers. */
    private function buoyHolding(array $numbers): string {
        $field = FieldDescriptor::collection('numbers', FieldDescriptor::scalar('numbers', ScalarType::Int, Nullability::NotNullable));
        Persistence::boot($this->connection, [new DynamicModule()])->saveSchemas(new EditorSave(1, [new AddField('dy:buoy', $field, new DefaultValue([]))]), new SystemActor());

        $set = ['color' => 'red', 'numbers' => count($numbers)];
        foreach ($numbers as $index => $number) {
            $set["numbers.$index"] = $number;
        }

        return $this->store('dy:buoy', $set);
    }

    /** Saves the buoy's color as the old release, touching nothing else. */
    private function oldReleaseRepaints(string $id, string $color): void {
        $session = $this->session();
        $buoy = $session->loadDynamic($id);
        $buoy->set('color', $color);
        $session->save($buoy);
    }

    /** Converts the items of $id into _value__v2, and marks the buoy's row. */
    private function convertItems(string $id): void {
        $this->connection->transactional(function () use ($id): void {
            Platforms::of($this->connection)->lockEntity($this->connection, $id);
            $this->connection->executeStatement($this->sql('UPDATE {@dy_buoy.numbers} SET _value__v2 = CAST(_value AS CHAR(20)) WHERE _owner = ?'), [$id]);
            $this->connection->executeStatement('UPDATE dy_buoy SET _moved__v2 = 1 WHERE _id = ?', [$id]);
        });
    }

    /** @return list<mixed> The column $column of $id's items, in order. */
    private function items(string $id, string $column): array {
        return $this->connection->fetchFirstColumn($this->sql(sprintf('SELECT %s FROM {@dy_buoy.numbers} WHERE _owner = ? ORDER BY _position', $column)), [$id]);
    }

    public function testACollectionsItemsAreCoveredByTheMarkerOnTheirOwnersRow(): void {
        $id = $this->buoyHolding([7, 8]);
        $this->connection->executeStatement($this->sql('ALTER TABLE {@dy_buoy.numbers} ADD COLUMN _value__v2 VARCHAR(255) NULL'));
        $this->connection->executeStatement('ALTER TABLE dy_buoy ADD COLUMN _moved__v2 SMALLINT NULL');
        $this->convertItems($id);
        self::assertSame(['7', '8'], $this->items($id, '_value__v2'));

        $this->oldReleaseRepaints($id, 'blue');

        self::assertNull($this->connection->fetchOne('SELECT _moved__v2 FROM dy_buoy WHERE _id = ?', [$id]), 'Rewriting the level row clears the marker.');
        self::assertSame([null, null], $this->items($id, '_value__v2'), 'Items rewrite with it, so no stale converted value stays.');
        self::assertSame([7, 8], array_map('intval', $this->items($id, '_value')), 'The old shape is whole, in order.');

        $this->convertItems($id);
        self::assertSame(['7', '8'], $this->items($id, '_value__v2'));
    }

    public function testASavedListThatShrankLeavesNoConvertedItemBehind(): void {
        $id = $this->buoyHolding([7, 8, 9]);
        $this->connection->executeStatement($this->sql('ALTER TABLE {@dy_buoy.numbers} ADD COLUMN _value__v2 VARCHAR(255) NULL'));
        $this->connection->executeStatement('ALTER TABLE dy_buoy ADD COLUMN _moved__v2 SMALLINT NULL');
        $this->convertItems($id);

        $session = $this->session();
        $buoy = $session->loadDynamic($id);
        $buoy->set('numbers', [7]);
        $session->save($buoy);
        $this->convertItems($id);

        self::assertSame(['7'], $this->items($id, '_value__v2'));
    }

    public function testAValueBecomingAListGetsANewTableTheOldReleaseClearsWithoutKnowingIt(): void {
        $id = $this->store('dy:buoy', ['color' => 'red']);
        $this->connection->executeStatement('ALTER TABLE dy_buoy ADD COLUMN _moved__v2 SMALLINT NULL');
        $this->connection->executeStatement('CREATE TABLE dy_buoy__colors__v2 (_owner CHAR(36) NOT NULL, _position INTEGER NOT NULL, _value VARCHAR(255) NULL, PRIMARY KEY (_owner, _position), FOREIGN KEY (_owner) REFERENCES dy_buoy (_id) ON DELETE CASCADE)');
        $convert = function () use ($id): void {
            $this->connection->transactional(function () use ($id): void {
                Platforms::of($this->connection)->lockEntity($this->connection, $id);
                $color = $this->connection->fetchOne($this->sql('SELECT {dy_buoy.color} FROM dy_buoy WHERE _id = ?'), [$id]);
                $this->connection->executeStatement('DELETE FROM dy_buoy__colors__v2 WHERE _owner = ?', [$id]);
                $this->connection->insert('dy_buoy__colors__v2', ['_owner' => $id, '_position' => 0, '_value' => $color]);
                $this->connection->executeStatement('UPDATE dy_buoy SET _moved__v2 = 1 WHERE _id = ?', [$id]);
            });
        };
        $colors = fn (): array => $this->connection->fetchFirstColumn('SELECT _value FROM dy_buoy__colors__v2 WHERE _owner = ?', [$id]);

        $convert();
        self::assertSame(['red'], $colors());

        $this->oldReleaseRepaints($id, 'blue');

        self::assertSame([], $colors(), 'The foreign key takes the converted rows with the level row: nothing stale stays.');
        $convert();
        self::assertSame(['blue'], $colors());
    }

    public function testAnOwnedObjectsRowIsClearedByItsRootsSaveAndConvertedUnderItsRootsLock(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5, 'dinghy' => 'dy:motor', 'dinghy.horsepower' => 40]);
        [$dinghy] = $this->ownedIds($yacht, 'dinghy');
        $this->connection->executeStatement('ALTER TABLE dy_motor ADD COLUMN horsepower__v2 VARCHAR(255) NULL');
        $this->connection->executeStatement('ALTER TABLE dy_motor ADD COLUMN _moved__v2 SMALLINT NULL');
        $convert = function () use ($yacht, $dinghy): void {
            $this->connection->transactional(function () use ($yacht, $dinghy): void {
                // A save locks the root of its aggregate: so must the backfill.
                Platforms::of($this->connection)->lockEntity($this->connection, $yacht);
                $this->connection->executeStatement($this->sql('UPDATE dy_motor SET horsepower__v2 = CAST({dy_motor.horsepower} AS CHAR(20)), _moved__v2 = 1 WHERE _id = ?'), [$dinghy]);
            });
        };
        $convert();
        self::assertSame('40', $this->storedIn('dy_motor', 'horsepower__v2', $dinghy));

        $this->oldReleaseSaves($yacht, 9);

        self::assertNull($this->storedIn('dy_motor', '_moved__v2', $dinghy), 'Saving the root rewrites what it owns, even unchanged.');
        self::assertNull($this->storedIn('dy_motor', 'horsepower__v2', $dinghy));
        $convert();
        self::assertSame('40', $this->storedIn('dy_motor', 'horsepower__v2', $dinghy));
    }

    /** Expands the buoy with a uniquely indexed lowercase color: a conversion that can make two values one. */
    private function expandUniqueColor(): void {
        $this->connection->executeStatement('ALTER TABLE dy_buoy ADD COLUMN color__v2 VARCHAR(255) NULL');
        $this->connection->executeStatement('ALTER TABLE dy_buoy ADD COLUMN _moved__v2 SMALLINT NULL');
        $this->connection->executeStatement('CREATE UNIQUE INDEX dy_buoy_color__v2 ON dy_buoy (color__v2)');
    }

    /**
     * Converts every buoy not converted yet, each on its own: a clash stops
     * that row, never the batch.
     * @return array<string, string> Why each row left behind failed, by id.
     */
    private function backfillColors(): array {
        $failures = [];
        foreach ($this->connection->fetchFirstColumn('SELECT _id FROM dy_buoy WHERE _moved__v2 IS NULL ORDER BY _id') as $id) {
            try {
                $this->connection->transactional(function () use ($id): void {
                    Platforms::of($this->connection)->lockEntity($this->connection, $id);
                    $color = $this->connection->fetchOne($this->sql('SELECT {dy_buoy.color} FROM dy_buoy WHERE _id = ?'), [$id]);
                    $this->connection->executeStatement('UPDATE dy_buoy SET color__v2 = ?, _moved__v2 = 1 WHERE _id = ?', [strtolower($color), $id]);
                });
            } catch (UniqueConstraintViolationException) {
                $failures[$id] = 'duplicate';
            }
        }

        return $failures;
    }

    public function testAUniqueIndexOnTheNewColumnStopsOnlyTheRowThatClashes(): void {
        $first = $this->store('dy:buoy', ['color' => 'Red']);
        $second = $this->store('dy:buoy', ['color' => 'RED']);
        $third = $this->store('dy:buoy', ['color' => 'Blue']);
        $this->expandUniqueColor();

        $failures = $this->backfillColors();

        self::assertCount(1, $failures, 'One of the two reds, and the batch went on.');
        $clashing = array_key_first($failures);
        self::assertContains($clashing, [$first, $second]);
        self::assertSame('blue', $this->storedIn('dy_buoy', 'color__v2', $third));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dy_buoy WHERE _moved__v2 IS NULL'), 'Left behind, and known.');
        self::assertNotNull($this->session()->loadDynamic($clashing), 'The old release still reads it.');

        $this->oldReleaseRepaints($clashing, 'Green');

        self::assertSame([], $this->backfillColors(), 'Fixed by a save, it converts.');
        self::assertSame('green', $this->storedIn('dy_buoy', 'color__v2', $clashing));
    }

    public function testAnOldReleaseSaveNeverTripsTheNewUniqueIndex(): void {
        $first = $this->store('dy:buoy', ['color' => 'red']);
        $this->store('dy:buoy', ['color' => 'blue']);
        $this->expandUniqueColor();
        $this->backfillColors();

        $this->oldReleaseRepaints($first, 'blue');

        self::assertNull($this->storedIn('dy_buoy', 'color__v2', $first), 'Empty, so nothing clashes yet.');
        self::assertCount(1, $this->backfillColors(), 'The clash surfaces in the backfill, on that row.');
    }

    public function testAddingAUniqueIndexOverDuplicatesFailsAndLeavesTheTableWorking(): void {
        $first = $this->store('dy:buoy', ['color' => 'red']);
        $this->store('dy:buoy', ['color' => 'red']);

        try {
            $this->connection->executeStatement($this->sql('CREATE UNIQUE INDEX dy_buoy_color_direct ON dy_buoy ({dy_buoy.color})'));
            self::fail('Duplicates should refuse the index.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $this->oldReleaseRepaints($first, 'green');
        self::assertSame('green', $this->session()->loadDynamic($first)->get('color'));
    }

    /** Adds a level a reparent puts above the yacht: its table, with a default-less flag, pointing at entities as a level does. */
    private function expandNewLevel(): void {
        $this->connection->executeStatement('CREATE TABLE dy_vessel (_id CHAR(36) NOT NULL, _owner CHAR(36) NOT NULL, _owner_field VARCHAR(255) NOT NULL, flag VARCHAR(255) NULL, PRIMARY KEY (_id), FOREIGN KEY (_id) REFERENCES entities (id) ON DELETE CASCADE)');
    }

    /** @return list<string> The yachts with no row at the new level: what a query on the new level would miss. */
    private function yachtsWithoutLevel(): array {
        return $this->connection->fetchFirstColumn("SELECT e.id FROM entities e LEFT JOIN dy_vessel v ON v._id = e.id WHERE e.concrete_identifier = 'dy:yacht' AND v._id IS NULL ORDER BY e.id");
    }

    /** Fills the new level of every yacht without one, each under its own lock. */
    private function backfillLevel(): void {
        foreach ($this->yachtsWithoutLevel() as $id) {
            $this->connection->transactional(function () use ($id): void {
                Platforms::of($this->connection)->lockEntity($this->connection, $id);
                $this->connection->insert('dy_vessel', ['_id' => $id, '_owner' => '00000000-0000-0000-0000-000000000000', '_owner_field' => '', 'flag' => 'yacht']);
            });
        }
    }

    public function testAGainedLevelIsFoundByWhatsMissingAndAnOldEntityIsPickedUp(): void {
        $first = $this->store('dy:yacht', ['cabins' => 5]);
        $this->expandNewLevel();
        $this->backfillLevel();
        self::assertSame([], $this->yachtsWithoutLevel());

        $late = $this->store('dy:yacht', ['cabins' => 6]);

        self::assertSame([$late], $this->yachtsWithoutLevel(), 'The old release knows no such level.');
        self::assertSame(6, $this->session()->loadDynamic($late)->get('cabins'), 'And it reads the entity without it.');
        $this->backfillLevel();
        self::assertSame([], $this->yachtsWithoutLevel());
        self::assertSame(5, $this->session()->loadDynamic($first)->get('cabins'));
    }

    public function testAnOldReleaseSaveLeavesTheGainedLevelAlone(): void {
        $id = $this->store('dy:yacht', ['cabins' => 5]);
        $this->expandNewLevel();
        $this->backfillLevel();

        $this->oldReleaseSaves($id, 9);

        self::assertSame('yacht', $this->storedIn('dy_vessel', 'flag', $id), 'Its chain has no such level: nothing clears it, so no marker would either.');
    }

    public function testDeletingAnEntityTakesItsGainedLevelWithIt(): void {
        $id = $this->store('dy:yacht', ['cabins' => 5]);
        $this->expandNewLevel();
        $this->backfillLevel();

        $session = $this->session();
        $session->deleteById($id);

        self::assertFalse($this->connection->fetchOne('SELECT _id FROM dy_vessel WHERE _id = ?', [$id]), 'The foreign key to entities does it, unknown to the old release.');
    }

    public function testABackfillStoppedPartWayCarriesOn(): void {
        $ids = [];
        for ($index = 0; $index < 5; $index++) {
            $ids[] = $this->store('dy:yacht', ['cabins' => $index]);
        }
        $spike = new RetypeSpike($this->connection);
        $spike->expand();

        $spike->backfill(2);
        self::assertSame(3, $spike->remaining());

        $restarted = new RetypeSpike($this->connection);
        self::assertSame(3, $restarted->backfill(100));
        self::assertSame(0, $restarted->remaining());
        foreach ($ids as $index => $id) {
            self::assertSame((string) $index, $restarted->read($id));
        }
    }

    public function testASaveWaitsForTheRowTheBackfillHolds(): void {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite has one writer: nothing to race.');
        }

        $id = $this->store('dy:yacht', ['cabins' => 5]);
        (new RetypeSpike($this->connection))->expand();

        $this->connection->beginTransaction();
        Platforms::of($this->connection)->lockEntity($this->connection, $id);

        $other = TestDatabase::connect();
        $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $other->beginTransaction();
            Platforms::of($other)->lockEntity($other, $id);
            self::fail('The save should wait for the backfill.');
        } catch (LockWaitTimeoutException) {
            $this->addToAssertionCount(1);
        } finally {
            $other->rollBack();
            $other->close();
            $this->connection->rollBack();
        }
    }
}
