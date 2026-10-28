<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\NoTypeCaptures;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationBlocked;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationReport;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\Migrator;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\DeveloperPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\MigrationPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\BrokenField;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\BrokenFieldReason;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\LostAncestor;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\LostAncestorReason;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** What a native change breaks on an editor-created type: blocked by strict, set aside by the developer policy, every value kept. */
final class MigratorBreaksTest extends TestCase
{
    private Connection $connection;
    private string $boatId;
    private string $vehicleId;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(MigratorEditorTypes::class);

        $this->migrate(MigratorEditorTypes::V1, MigrationSteps::none());
        [$schemas, $values, $registry] = $this->stores();
        $boat = MigratorEditorTypes::boat();
        MigratorEditorTypes::create($this->connection, $schemas, $values, $registry, ['ed:boat' => $boat]);

        $this->boatId = MigratorEditorTypes::save($this->connection, $values, $registry, 'ed:boat', [
            'hull' => 'steel',
            'engine' => 'ed:engine',
            'engine.horsepower' => 300,
        ]);
        $vehicle = new EdVehicle();
        $vehicle->name = 'Van';
        $this->vehicleId = (new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap()))->save($vehicle);
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** @return array{SchemaStore, SchemaFieldValueStore, SchemaRegistry} What a new request starts with. */
    private function stores(): array {
        $codec = new EntitySchemaCodec(new FieldValidatorRegistry(), new PrototypeValidatorRegistry(), new ValueObjectRegistry(), new EnumRegistry());
        $schemas = new SchemaStore($this->connection, $codec);

        return [$schemas, new SchemaFieldValueStore($this->connection), new SchemaRegistry($schemas)];
    }

    /** @param list<class-string> $classes */
    private function migrate(array $classes, MigrationSteps $steps, MigrationPolicy $policy = new StrictPolicy()): MigrationResult {
        [$schemas, $values, $registry] = $this->stores();

        return (new Migrator($this->connection, $registry, $schemas, $values, $policy))->migrate(NativeEntityReader::readAll($classes), $steps);
    }

    /** @param list<class-string> $classes */
    private function check(array $classes, MigrationSteps $steps, MigrationPolicy $policy = new StrictPolicy()): MigrationReport {
        [$schemas, $values, $registry] = $this->stores();

        return (new Migrator($this->connection, $registry, $schemas, $values, $policy))->check(NativeEntityReader::readAll($classes), $steps);
    }

    private function boatSchema(): EntitySchema {
        return $this->stores()[0]->load('ed:boat')->schema;
    }

    /** The boat's broken-field record for $name. */
    private function brokenField(string $name): BrokenField {
        foreach ($this->boatSchema()->noTypeRecords as $record) {
            if ($record instanceof BrokenField && $record->name === $name) {
                return $record;
            }
        }

        self::fail(sprintf('The boat has no broken-field record for "%s".', $name));
    }

    /** @return array<string, mixed> The boat's captures for $record, by site. */
    private function captured(BrokenField $record): array {
        return (new NoTypeCaptures($this->connection))->of($this->boatId, $record->id) ?? [];
    }

    private function boatStillLoads(): void {
        [, $values, $registry] = $this->stores();
        self::assertSame('ed:boat', (new EntityReader($this->connection, $registry, $values))->read($this->boatId)->identifier);
    }

    public function testStrictBlocksABreakAndChangesNothing(): void {
        try {
            $this->migrate([...array_diff(MigratorEditorTypes::V1, [EdEngine::class])], MigrationSteps::none()->add('no-engines', [new DropType('ed:engine')]));
            self::fail('Strict should block.');
        } catch (MigrationBlocked $blocked) {
            self::assertStringContainsString('Field "engine" of editor-created type "ed:boat" points at type "ed:engine", which is gone.', $blocked->getMessage());
        }

        self::assertTrue($this->boatSchema()->fields->has(FieldName::of('engine')));
        self::assertSame([], $this->boatSchema()->noTypeRecords);
    }

    public function testADryRunReportsWhatStrictBlocksAndChangesNothing(): void {
        $stateBefore = $this->connection->fetchAssociative('SELECT * FROM migration_state');

        $report = $this->check([...array_diff(MigratorEditorTypes::V1, [EdEngine::class])], MigrationSteps::none()->add('no-engines', [new DropType('ed:engine')]));

        self::assertTrue($report->pending);
        self::assertTrue($report->isBlocked());
        self::assertSame(['no-engines'], $report->pendingSteps);
        self::assertCount(1, $report->breaks);
        self::assertTrue($report->breaks[0]->isBlocked());
        self::assertSame('ed:boat', $report->breaks[0]->break->identifier());
        self::assertStringContainsString('Break: Field "engine" of editor-created type "ed:boat" points at type "ed:engine", which is gone. Blocked.', $report->describe());
        self::assertTrue($this->boatSchema()->fields->has(FieldName::of('engine')), 'A dry run changes nothing.');
        self::assertSame($stateBefore, $this->connection->fetchAssociative('SELECT * FROM migration_state'));
    }

    public function testADryRunReportsWhatALooserPolicySetsAsideAndWhere(): void {
        $report = $this->check([...array_diff(MigratorEditorTypes::V1, [EdEngine::class])], MigrationSteps::none()->add('no-engines', [new DropType('ed:engine')]), new DeveloperPolicy());

        self::assertFalse($report->isBlocked());
        self::assertFalse($report->breaks[0]->isBlocked());
        self::assertSame(['SetAsideField(ed:boat, engine, target_gone)', 'DropType(ed:engine)'], $report->fixups, 'Set aside just before the fixup that broke it.');
        self::assertTrue($this->boatSchema()->fields->has(FieldName::of('engine')), 'A dry run changes nothing.');
    }

    public function testADryRunWithNothingToDoSaysSo(): void {
        $report = $this->check(MigratorEditorTypes::V1, MigrationSteps::none());

        self::assertFalse($report->pending);
        self::assertSame([], $report->breaks);
        self::assertSame('The database matches the code: nothing to migrate.', $report->describe());
    }

    public function testADryRunOnAnEmptyDatabaseSaysItWouldBeCreated(): void {
        TestDatabase::dropEverything($this->connection);

        $report = $this->check(MigratorEditorTypes::V1, MigrationSteps::none());

        self::assertTrue($report->fresh);
        self::assertSame([], $this->connection->createSchemaManager()->listTableNames(), 'Not even the stores are created.');
    }

    public function testAFieldWhoseTargetIsGoneIsSetAsideWithItsOwnedObjectWhole(): void {
        $engineId = $this->connection->fetchOne("SELECT id FROM entities WHERE owner = ? AND owner_field = 'engine'", [$this->boatId]);

        $this->migrate([...array_diff(MigratorEditorTypes::V1, [EdEngine::class])], MigrationSteps::none()->add('no-engines', [new DropType('ed:engine')]), new DeveloperPolicy());

        self::assertFalse($this->boatSchema()->fields->has(FieldName::of('engine')));
        $record = $this->brokenField('engine');
        self::assertSame(BrokenFieldReason::TargetGone, $record->reason);
        self::assertSame('ed:boat', $record->origin);
        self::assertSame(FieldKind::OwningReference, $record->descriptor->kind());
        self::assertSame('ed:engine', $record->descriptor->target());

        $capture = $this->captured($record)[''] ?? [];
        self::assertEquals(['' => 'ed:engine', '.horsepower' => 300], $capture['values'] ?? null, 'Captured before DropType emptied the slot.');
        self::assertEqualsCanonicalizing(['', '.horsepower'], $capture['touched'] ?? null);
        self::assertSame(['' => $engineId], $capture['ownedIds'] ?? null, 'Its id is kept, to recreate it.');
        self::assertFalse($this->connection->fetchOne('SELECT id FROM entities WHERE id = ?', [$engineId]), 'The owned row itself goes.');
        $this->boatStillLoads();
    }

    public function testAFieldWhoseNameNowClashesLeavesTheNameToTheNativeField(): void {
        $this->migrate([...array_diff(MigratorEditorTypes::V1, [EdVehicle::class]), EdVehicleWithHull::class], MigrationSteps::none(), new DeveloperPolicy());

        $record = $this->brokenField('hull');
        self::assertSame(BrokenFieldReason::NameClash, $record->reason);
        self::assertSame(['' => 'steel'], $this->captured($record)['']['values'] ?? null);
        self::assertSame('none', $this->connection->fetchOne('SELECT hull FROM ed_vehicle WHERE _id = ?', [$this->boatId]), 'The native field, with its own default: never the boat\'s old value.');
        self::assertFalse($this->boatSchema()->fields->has(FieldName::of('hull')));
        $this->boatStillLoads();
    }

    public function testAClashingFieldIsCapturedBeforeTheInitializerOfTheFieldTakingItsName(): void {
        $this->migrate(
            [...array_diff(MigratorEditorTypes::V1, [EdVehicle::class]), EdVehicleWithHull::class],
            MigrationSteps::none()->add('hull', [new AddField('ed:vehicle', 'hull', new EdPaintedHull())]),
            new DeveloperPolicy(),
        );

        self::assertSame(['' => 'steel'], $this->captured($this->brokenField('hull'))['']['values'] ?? null, 'The boat\'s own value, never the initializer\'s.');
        self::assertSame('painted', $this->connection->fetchOne('SELECT hull FROM ed_vehicle WHERE _id = ?', [$this->boatId]), 'The boat is a vehicle: the initializer fills the native field.');
    }

    public function testACutChainIsCapturedAfterTheStepsBeforeTheCut(): void {
        $this->migrate([...array_diff(MigratorEditorTypes::V1, [EdVehicle::class])], MigrationSteps::none()
            ->add('cost', [new RenameField('ed:vehicle', 'price', 'cost')])
            ->add('no-vehicles', [new DropType('ed:vehicle')]), new DeveloperPolicy());

        self::assertNull($this->boatSchema()->parent, 'No ancestor is left: it becomes a root.');
        $lost = array_values(array_filter($this->boatSchema()->noTypeRecords, static fn (object $record): bool => $record instanceof LostAncestor));
        self::assertCount(1, $lost);
        self::assertSame('ed:vehicle', $lost[0]->ancestor);
        self::assertSame(LostAncestorReason::Dropped, $lost[0]->reason);

        $cost = $this->brokenField('cost');
        self::assertSame(BrokenFieldReason::ChainCut, $cost->reason);
        self::assertSame('ed:vehicle', $cost->origin);
        self::assertSame(['' => 5], $this->captured($cost)['']['values'] ?? null, 'Renamed by the step before the cut: captured under its new name.');
        self::assertSame(['' => 0], $this->captured($this->brokenField('wheels'))['']['values'] ?? null);
        self::assertFalse($this->connection->fetchOne('SELECT id FROM entities WHERE id = ?', [$this->vehicleId]), 'The van went with its type.');
        $this->boatStillLoads();
    }

    public function testAParentNoLongerAllowingItCutsTheChainOnceEveryFixupRan(): void {
        $this->migrate([...array_diff(MigratorEditorTypes::V1, [EdVehicle::class]), EdSealedVehicle::class], MigrationSteps::none(), new DeveloperPolicy());

        self::assertNull($this->boatSchema()->parent);
        $lost = array_values(array_filter($this->boatSchema()->noTypeRecords, static fn (object $record): bool => $record instanceof LostAncestor));
        self::assertSame(LostAncestorReason::NotExtensible, $lost[0]->reason ?? null);
        self::assertSame(['' => 'white'], $this->captured($this->brokenField('color'))['']['values'] ?? null);
        self::assertSame('Van', $this->connection->fetchOne('SELECT name FROM ed_vehicle WHERE _id = ?', [$this->vehicleId]), 'The van, a vehicle itself, stays.');
        $this->boatStillLoads();
    }
}
