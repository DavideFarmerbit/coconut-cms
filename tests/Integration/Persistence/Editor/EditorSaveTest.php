<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Editor;

use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\MigrateOn;
use XyloIsCoding\CoconutCms\Persistence\Migration\BreakResolution;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConstantValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\CopyOfField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\OnFailure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToNumber;
use XyloIsCoding\CoconutCms\Persistence\PersistenceStatus;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\BreaksNotAccepted;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSaveReport;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EntriesFailToMove;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CreateType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\DeleteType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\InheritDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\StaleSchemaRevision;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationBlocked;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\DeveloperPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\MigrationPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicModule;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicTestCase;

/**
 * Editor saves, run as migrations over the dynamic tests' types: a yacht
 * extending the native craft, an outboard, a buoy. The database is at
 * revision 1 once migrated; the editor types were compiled in after.
 */
final class EditorSaveTest extends DynamicTestCase
{
    private function persistence(MigrationPolicy $policy = new StrictPolicy()): Persistence {
        return Persistence::boot($this->connection, [new DynamicModule()], null, MigrateOn::Command, $policy);
    }

    /** Saves $operations at revision 1, as an admin who loaded the types once. */
    private function save(SchemaOperation ...$operations): EditorSaveReport {
        return $this->persistence()->saveSchemas(new EditorSave(1, $operations));
    }

    private function revision(): int {
        return (new MigrationState($this->connection))->revision();
    }

    /** @return array<string, mixed> What a new $type entity holds, by field. */
    private function defaultsOf(string $type): array {
        return $this->session()->defaultsOf($type)->values;
    }

    public function testRenamingAFieldKeepsItsValuesAndItsDefault(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);

        $report = $this->save(new RenameField('dy:yacht', 'cabins', 'berths'));

        self::assertSame(['RenameField(dy:yacht, cabins → berths)'], $report->fixups);
        self::assertSame(2, $report->revision);
        self::assertSame(2, $this->revision());
        self::assertSame(5, $this->session()->loadDynamic($yacht)->get('berths'));
        self::assertSame(3, $this->defaultsOf('dy:yacht')['berths']);
        self::assertArrayNotHasKey('cabins', $this->defaultsOf('dy:yacht'));
    }

    public function testWhatTheAdminUndidNeverRuns(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);

        $report = $this->save(
            new RenameField('dy:yacht', 'cabins', 'rooms'),
            new AddField('dy:yacht', FieldDescriptor::scalar('masts', ScalarType::Int, Nullability::NotNullable), new DefaultValue(1)),
            new RenameField('dy:yacht', 'rooms', 'berths'),
            new SetDefault('dy:yacht', 'berths', new DefaultValue(6)),
            new RemoveField('dy:yacht', 'masts'),
            new SetDefault('dy:yacht', 'berths', new DefaultValue(3)),
        );

        self::assertSame(['RenameField(dy:yacht, cabins → berths)'], array_map(static fn (SchemaOperation $operation): string => $operation->describe(), $report->operations));
        self::assertSame(['RenameField(dy:yacht, cabins → berths)'], $report->fixups);
        self::assertSame(5, $this->session()->loadDynamic($yacht)->get('berths'));
        self::assertSame(3, $this->defaultsOf('dy:yacht')['berths']);
    }

    public function testAnAddedFieldFillsExistingEntriesAndPinsTheDefaultForNewOnes(): void {
        $yacht = $this->store('dy:yacht', ['name' => 'Sea']);

        $this->save(
            new AddField('dy:yacht', FieldDescriptor::scalar('masts', ScalarType::Int, Nullability::NotNullable), new DefaultValue(1), new ConstantValue(2)),
            new AddField('dy:yacht', FieldDescriptor::scalar('title', ScalarType::String, Nullability::NotNullable), new DefaultValue(''), new CopyOfField('name')),
        );

        $loaded = $this->session()->loadDynamic($yacht);
        self::assertSame(2, $loaded->get('masts'));
        self::assertSame('Sea', $loaded->get('title'));
        self::assertSame(1, $this->defaultsOf('dy:yacht')['masts']);
    }

    public function testARetypedFieldConvertsItsValuesAndTheDefaultTheTypeSets(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);

        $this->save(new RetypeField('dy:yacht', FieldDescriptor::scalar('cabins', ScalarType::String, Nullability::NotNullable), new NumberToText()));

        self::assertSame('5', $this->session()->loadDynamic($yacht)->get('cabins'));
        self::assertSame('3', $this->defaultsOf('dy:yacht')['cabins']);
    }

    public function testDefaultChangesApplyOnceTheContentMoved(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);

        $this->save(new RenameField('dy:yacht', 'cabins', 'berths'), new SetDefault('dy:yacht', 'berths', new DefaultValue(4)), new InheritDefault('dy:yacht', 'name'));

        self::assertSame(4, $this->defaultsOf('dy:yacht')['berths']);
        self::assertSame('', $this->defaultsOf('dy:yacht')['name'], 'Inherited from the craft again.');
        $loaded = $this->session()->loadDynamic($yacht);
        self::assertSame(5, $loaded->get('berths'), 'Set: it keeps its value.');
        self::assertSame('', $loaded->get('name'), 'Following the default: it follows the new one.');
    }

    public function testADefaultPathFollowsARenameOfItsFirstFieldOnly(): void {
        $this->save(
            new CreateType('dy:sail'),
            new AddField('dy:sail', FieldDescriptor::scalar('area', ScalarType::Int, Nullability::NotNullable), new DefaultValue(10)),
            new AddField('dy:yacht', FieldDescriptor::embed('sail', 'dy:sail'), null),
        );

        try {
            $this->persistence()->saveSchemas(new EditorSave(2, [new SetDefault('dy:yacht', 'sail:area', new DefaultValue(20)), new RenameField('dy:sail', 'area', 'size')]));
            self::fail('The path inside the Embed isn\'t followed: the default would be lost.');
        } catch (InvalidOperation $refused) {
            self::assertStringContainsString('following a rename inside it is not built yet', $refused->getMessage());
        }

        self::assertSame(2, $this->revision(), 'Nothing ran.');
        $this->persistence()->saveSchemas(new EditorSave(2, [new RenameField('dy:sail', 'area', 'size'), new SetDefault('dy:yacht', 'sail:size', new DefaultValue(20))]));
        self::assertSame(20, $this->session()->newDynamic('dy:yacht')->get('sail:size'));
    }

    public function testACreatedTypeIsReadyWithItsFieldsAndDefaults(): void {
        $report = $this->save(
            new CreateType('dy:dinghy', 'dy:craft'),
            new AddField('dy:dinghy', FieldDescriptor::scalar('oars', ScalarType::Int, Nullability::NotNullable), new DefaultValue(2)),
        );

        self::assertSame([], $report->fixups);
        self::assertSame(2, $this->revision());
        $session = $this->session();
        $dinghy = $session->newDynamic('dy:dinghy');
        self::assertSame(2, $dinghy->get('oars'));
        $dinghy->set('name', 'Little');
        self::assertSame('Little', $session->loadDynamic($session->save($dinghy))->get('name'));
    }

    public function testARenamedTypeKeepsItsEntities(): void {
        $buoy = $this->store('dy:buoy', ['color' => 'green']);

        $this->save(new RenameType('dy:buoy', 'dy:marker'));

        self::assertSame('dy:marker', $this->typeOf($buoy));
        self::assertSame('green', $this->session()->loadDynamic($buoy)->get('color'));
    }

    public function testABreakIsTheProjectPolicysAndTheAdminMustAcceptIt(): void {
        $this->save(new AddField('dy:yacht', FieldDescriptor::reference('mooring', 'dy:buoy'), new DefaultValue(null)));
        $delete = [new DeleteType('dy:buoy')];

        try {
            $this->persistence()->saveSchemas(new EditorSave(2, $delete, acceptBreaks: true));
            self::fail('The strict policy blocks a break.');
        } catch (MigrationBlocked) {
        }

        $preview = $this->persistence(new DeveloperPolicy())->previewSchemas(new EditorSave(2, $delete));
        self::assertSame(['mooring'], array_map(static fn (BreakResolution $each): string => (string) $each->break->field, $preview->breaks));
        self::assertFalse($preview->isBlocked());

        try {
            $this->persistence(new DeveloperPolicy())->saveSchemas(new EditorSave(2, $delete));
            self::fail('A break must be accepted.');
        } catch (BreaksNotAccepted $refused) {
            self::assertCount(1, $refused->breaks);
        }

        self::assertSame(2, $this->revision(), 'Nothing ran.');
        $this->persistence(new DeveloperPolicy())->saveSchemas(new EditorSave(2, $delete, acceptBreaks: true));

        self::assertSame(3, $this->revision());
        self::assertFalse($this->session()->schemas()->has('dy:buoy'));
        self::assertArrayNotHasKey('mooring', $this->defaultsOf('dy:yacht'), 'Set aside.');
    }

    public function testAConverterThatCancelsFailsTheSave(): void {
        // The type's own default converts; one buoy's color doesn't.
        $this->save(new SetDefault('dy:buoy', 'color', new DefaultValue('7')));
        $buoy = $this->store('dy:buoy', ['color' => 'red']);
        $this->store('dy:buoy', ['color' => '12']);
        $retype = [new RetypeField('dy:buoy', FieldDescriptor::scalar('color', ScalarType::Int, Nullability::NotNullable), new TextToNumber(ScalarType::Int, OnFailure::Cancel))];

        $preview = $this->persistence()->previewSchemas(new EditorSave(2, $retype));
        self::assertSame([$buoy => 'TextToNumber can\'t convert "red".'], $preview->failures);
        self::assertTrue($preview->isBlocked());

        try {
            $this->persistence()->saveSchemas(new EditorSave(2, $retype));
            self::fail('"red" is no number.');
        } catch (EntriesFailToMove $refused) {
            self::assertSame([$buoy], array_keys($refused->failures));
        }

        self::assertSame(2, $this->revision());
        self::assertSame(PersistenceStatus::Ready, $this->persistence()->status());
        self::assertSame('red', $this->session()->loadDynamic($buoy)->get('color'));
    }

    public function testASaveBuiltOnAnOldRevisionIsRefused(): void {
        $this->expectException(StaleSchemaRevision::class);
        $this->expectExceptionMessage('The types changed since revision 0; they are at revision 1 now.');

        $this->persistence()->saveSchemas(new EditorSave(0, [new RenameType('dy:buoy', 'dy:marker')]));
    }

    public function testAnOperationOnANativeTypeIsRefused(): void {
        $this->expectException(InvalidOperation::class);

        $this->save(new RenameType('dy:craft', 'dy:boat'));
    }

    public function testAPreviewChangesNothing(): void {
        $report = $this->persistence()->previewSchemas(new EditorSave(1, [new RenameField('dy:yacht', 'cabins', 'berths')]));

        self::assertSame(['RenameField(dy:yacht, cabins → berths)'], $report->fixups);
        self::assertSame(1, $report->revision);
        self::assertSame(1, $this->revision());
        self::assertArrayHasKey('cabins', $this->defaultsOf('dy:yacht'));
    }
}
