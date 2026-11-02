<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Editor;

use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\MigrateOn;
use XyloIsCoding\CoconutCms\Persistence\Migration\BreakResolution;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConstantValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\CopyOfField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\FirstItem;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToNumber;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\OnFailure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\TextToNumber;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ToSingleItem;
use XyloIsCoding\CoconutCms\Persistence\PersistenceStatus;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\BreaksNotAccepted;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSaveReport;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddUniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CopyValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveUniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\ReplaceType;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DyBoatMotor;
use XyloIsCoding\CoconutCms\Persistence\Migration\ContentRefused;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CreateType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\DeleteType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\InheritDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\MigratingValues;
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
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaEditingDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermActor;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermissionFixtures;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicModule;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicTestCase;

/** Flags every yacht moving under a vessel: what a test's Reparent fills the vessel's level with. */
final readonly class FlagOfYachts implements LevelInitializer
{
    public function initialize(MigratingValues $values): void {
        $values->set('flag', Conversion::of('yacht'));
    }
}

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
        return $this->saveAs(new StrictPolicy(), new EditorSave(1, $operations));
    }

    /** Runs $save as the system, under $policy. */
    private function saveAs(MigrationPolicy $policy, EditorSave $save): EditorSaveReport {
        return $this->persistence($policy)->saveSchemas($save, new SystemActor());
    }

    /** Previews $save as the system, under $policy. */
    private function previewAs(MigrationPolicy $policy, EditorSave $save): EditorSaveReport {
        return $this->persistence($policy)->previewSchemas($save, new SystemActor());
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

    public function testOperationsRunAsGivenAndADefaultFollowsALaterRename(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);

        $report = $this->save(
            new SetDefault('dy:yacht', 'cabins', new DefaultValue(6)),
            new RenameField('dy:yacht', 'cabins', 'rooms'),
            new AddField('dy:yacht', FieldDescriptor::scalar('masts', ScalarType::Int, Nullability::NotNullable), new DefaultValue(1)),
            new SetDefault('dy:yacht', 'masts', new DefaultValue(2)),
            new RemoveField('dy:yacht', 'masts'),
        );

        self::assertSame(['RenameField(dy:yacht, cabins → rooms)', 'AddField(dy:yacht, masts)', 'DropField(dy:yacht, masts)'], $report->fixups, 'Nothing is folded: what was sent runs.');
        self::assertCount(5, $report->operations);
        self::assertSame(5, $this->session()->loadDynamic($yacht)->get('rooms'));
        self::assertSame(6, $this->defaultsOf('dy:yacht')['rooms'], 'Set before the rename, pinned under the new name.');
        self::assertArrayNotHasKey('masts', $this->defaultsOf('dy:yacht'));
    }

    public function testEachFixupHeadsForItsOwnStep(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);

        $this->save(
            new RetypeField('dy:yacht', FieldDescriptor::scalar('cabins', ScalarType::String, Nullability::NotNullable), new NumberToText()),
            new RetypeField('dy:yacht', FieldDescriptor::scalar('cabins', ScalarType::Float, Nullability::NotNullable), new TextToNumber(OnFailure::Cancel)),
        );

        self::assertSame(5.0, $this->session()->loadDynamic($yacht)->get('cabins'), 'Int to text, then text to float: each converter gives its own step\'s field.');
        self::assertEquals(3.0, $this->defaultsOf('dy:yacht')['cabins'], 'Converted twice too; a Float default reads back as 3, not 3.0.');
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

    public function testAnAddedCollectionStartsEmptyOrWithTheItemsItsDefaultGives(): void {
        $buoy = $this->store('dy:buoy', ['color' => 'red']);
        $tags = FieldDescriptor::collection('tags', FieldDescriptor::scalar('tags', ScalarType::String, Nullability::NotNullable));

        $this->save(new AddField('dy:buoy', $tags, new DefaultValue([])));
        self::assertSame([], $this->session()->loadDynamic($buoy)->get('tags'));

        $this->saveAs(new StrictPolicy(), new EditorSave(2, [new SetDefault('dy:buoy', 'tags', new DefaultValue(['sea', 'night', 'north']))]));
        $this->saveAs(new StrictPolicy(), new EditorSave(3, [new SetDefault('dy:buoy', 'tags', new DefaultValue(['sea']))]));
        $stored = $this->stores()[1]->load('dy:buoy')->toArray();
        self::assertSame(['tags' => 1, 'tags.0' => 'sea'], array_intersect_key($stored, array_flip(['tags', 'tags.0', 'tags.1', 'tags.2'])), 'A shorter list leaves no item behind.');
        self::assertSame(['sea'], $this->session()->newDynamic('dy:buoy')->get('tags'));
    }

    public function testAValueBecomesAListOfOneAndBackThroughAStoredSave(): void {
        $red = $this->store('dy:buoy', ['color' => 'red']);
        $none = $this->store('dy:buoy', []);
        $colors = FieldDescriptor::collection('color', FieldDescriptor::scalar('color', ScalarType::String, Nullability::NotNullable));

        $this->save(new RetypeField('dy:buoy', $colors, new ToSingleItem(), new DefaultValue([])));
        self::assertSame(['red'], $this->session()->loadDynamic($red)->get('color'));
        self::assertSame([], $this->session()->loadDynamic($none)->get('color'), 'It followed the default: it follows the new one.');

        $this->saveAs(new StrictPolicy(), new EditorSave(2, [new RetypeField('dy:buoy', FieldDescriptor::scalar('color', ScalarType::String, Nullability::NotNullable), new FirstItem(), new DefaultValue('white'))]));
        self::assertSame('red', $this->session()->loadDynamic($red)->get('color'));
        self::assertSame('white', $this->session()->loadDynamic($none)->get('color'));
    }

    public function testANumberIsRetypedAndRounded(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 4]);

        $this->save(new RetypeField('dy:yacht', FieldDescriptor::scalar('cabins', ScalarType::Decimal, Nullability::NotNullable), new NumberToNumber(OnFailure::Cancel)));

        self::assertSame('4.000000', $this->session()->loadDynamic($yacht)->get('cabins'));
    }

    public function testACollectionsDefaultIsAList(): void {
        $tags = FieldDescriptor::collection('tags', FieldDescriptor::scalar('tags', ScalarType::String, Nullability::NotNullable));
        $motors = FieldDescriptor::collection('motors', FieldDescriptor::owningReference('motors', 'dy:motor', Nullability::Nullable));

        foreach ([
            [new AddField('dy:buoy', $tags, new DefaultValue('sea')), 'its default is a list of its items'],
            [new AddField('dy:buoy', $motors, new DefaultValue(['dy:motor'])), 'a default with items isn\'t offered yet'],
        ] as [$operation, $message]) {
            try {
                $this->save($operation);
                self::fail($message);
            } catch (InvalidOperation $refused) {
                self::assertStringContainsString($message, $refused->getMessage());
            }
        }
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

    public function testADefaultSetThroughAnEmbedFollowsARenameInsideIt(): void {
        $this->save(
            new CreateType('dy:sail'),
            new AddField('dy:sail', FieldDescriptor::scalar('area', ScalarType::Int, Nullability::NotNullable), new DefaultValue(10)),
            new AddField('dy:yacht', FieldDescriptor::embed('sail', 'dy:sail'), null),
        );

        $this->saveAs(new StrictPolicy(), new EditorSave(2, [new SetDefault('dy:yacht', 'sail:area', new DefaultValue(20)), new RenameField('dy:sail', 'area', 'size')]));

        self::assertSame(20, $this->session()->newDynamic('dy:yacht')->get('sail:size'), 'Set before the rename inside the Embed, pinned under the new name.');
        self::assertSame(10, $this->session()->newDynamic('dy:sail')->get('size'));
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

    public function testAFieldMovedUpReachesEveryEntryOfTheAncestorAndMovedDownLeavesThem(): void {
        $this->save(
            new CreateType('dy:schooner', 'dy:yacht'),
            new AddField('dy:schooner', FieldDescriptor::scalar('masts', ScalarType::Int, Nullability::NotNullable), new DefaultValue(2)),
        );
        $schooner = $this->store('dy:schooner', ['masts' => 3]);
        $yacht = $this->store('dy:yacht', ['cabins' => 4]);

        $report = $this->saveAs(new StrictPolicy(), new EditorSave(2, [new MoveField('dy:schooner', 'masts', 'dy:yacht', new DefaultValue(0), new ConstantValue(1))]));

        self::assertSame(['MoveField(masts, dy:schooner → dy:yacht, ' . ConstantValue::class . ')'], $report->fixups);
        self::assertSame(1, $this->session()->loadDynamic($yacht)->get('masts'), 'A yacht gains it: the initializer decides.');
        self::assertSame(3, $this->session()->loadDynamic($schooner)->get('masts'), 'A schooner keeps its own.');
        self::assertSame(0, $this->defaultsOf('dy:yacht')['masts']);
        self::assertSame(2, $this->defaultsOf('dy:schooner')['masts'], 'What the schooner set itself stays.');

        $this->saveAs(new StrictPolicy(), new EditorSave(3, [new MoveField('dy:yacht', 'masts', 'dy:schooner', new DefaultValue(2))]));

        self::assertArrayNotHasKey('masts', $this->defaultsOf('dy:yacht'));
        self::assertSame(3, $this->session()->loadDynamic($schooner)->get('masts'));
    }

    public function testAReparentedTypeFillsTheLevelsItGainsAndLosesTheOnesItLeaves(): void {
        $this->save(
            new CreateType('dy:vessel', 'dy:craft'),
            new AddField('dy:vessel', FieldDescriptor::scalar('flag', ScalarType::String, Nullability::NotNullable), new DefaultValue('none')),
        );
        $yacht = $this->store('dy:yacht', ['name' => 'Sea', 'cabins' => 4]);

        $report = $this->saveAs(new StrictPolicy(), new EditorSave(2, [new Reparent('dy:yacht', 'dy:vessel', new FlagOfYachts())]));

        self::assertSame(['Reparent(dy:yacht, ' . FlagOfYachts::class . ')'], $report->fixups);
        $loaded = $this->session()->loadDynamic($yacht);
        self::assertSame('yacht', $loaded->get('flag'), 'The level gained, filled by the initializer.');
        self::assertSame('Sea', $loaded->get('name'), 'The levels kept keep their values.');
        self::assertSame(4, $loaded->get('cabins'));
        self::assertSame('none', $this->defaultsOf('dy:yacht')['flag'], 'A new yacht follows the vessel.');

        $this->saveAs(new StrictPolicy(), new EditorSave(3, [new Reparent('dy:yacht', 'dy:craft')]));

        self::assertArrayNotHasKey('flag', $this->defaultsOf('dy:yacht'));
        self::assertSame('Sea', $this->session()->loadDynamic($yacht)->get('name'));
    }

    public function testCopiedValuesFillAFieldAndTheSourceKeepsThem(): void {
        $named = $this->store('dy:yacht', ['name' => 'Sea']);
        $unnamed = $this->store('dy:yacht', ['cabins' => 2]);

        $report = $this->save(
            new AddField('dy:yacht', FieldDescriptor::scalar('title', ScalarType::String, Nullability::NotNullable), new DefaultValue('')),
            new CopyValues('dy:yacht', 'name', 'title'),
        );

        self::assertSame(['AddField(dy:yacht, title)', 'CopyValues(dy:yacht, name → title)'], $report->fixups);
        self::assertSame(['Sea', 'Sea'], [$this->session()->loadDynamic($named)->get('title'), $this->session()->loadDynamic($named)->get('name')]);
        self::assertSame('Yacht', $this->session()->loadDynamic($unnamed)->get('title'), 'Following the name\'s default, copied and pinned.');
        self::assertSame('', $this->defaultsOf('dy:yacht')['title'], 'A copy changes entries, never the type\'s defaults.');
    }

    public function testACopyNeedsFieldsHoldingTheSameThing(): void {
        foreach ([
            ['hold different things', new CopyValues('dy:yacht', 'cabins', 'name')],
            ['holds owned objects', new CopyValues('dy:yacht', 'dinghy', 'motor')],
        ] as [$message, $operation]) {
            try {
                $this->save($operation);
                self::fail($message);
            } catch (InvalidOperation $refused) {
                self::assertStringContainsString($message, $refused->getMessage());
                self::assertSame(0, $refused->index);
            }
        }
    }

    public function testAUniqueGroupOverDuplicatesIsRefusedListingThem(): void {
        $red = $this->store('dy:buoy', ['color' => 'red']);
        $alsoRed = $this->store('dy:buoy', ['color' => 'red']);
        $this->store('dy:buoy', ['color' => 'green']);
        $group = new EditorSave(1, [new AddUniqueGroup('dy:buoy', 'color')]);

        $preview = $this->previewAs(new StrictPolicy(), $group);
        self::assertCount(1, $preview->duplicates);
        self::assertSame(['color' => 'red'], $preview->duplicates[0]->values);
        self::assertEqualsCanonicalizing([$red, $alsoRed], $preview->duplicates[0]->entities);

        try {
            $this->saveAs(new StrictPolicy(), $group);
            self::fail('Two buoys are red.');
        } catch (ContentRefused) {
        }

        self::assertSame(1, $this->revision(), 'Nothing changed.');
        $this->connection->executeStatement('UPDATE dy_buoy SET color = ? WHERE _id = ?', ['blue', $alsoRed]);
        $this->saveAs(new StrictPolicy(), $group);
        self::assertSame(2, $this->revision());

        $this->saveAs(new StrictPolicy(), new EditorSave(2, [new RemoveUniqueGroup('dy:buoy', 'color')]));
        self::assertSame(3, $this->revision());
    }

    public function testAConverterGivingTwoEntriesOneValueBreaksTheirGroup(): void {
        $this->save(new SetDefault('dy:buoy', 'color', new DefaultValue('0')), new AddUniqueGroup('dy:buoy', 'color'));
        $this->store('dy:buoy', ['color' => '7']);
        $this->store('dy:buoy', ['color' => '07']);

        $this->expectException(ContentRefused::class);

        $this->saveAs(new StrictPolicy(), new EditorSave(2, [new RetypeField('dy:buoy', FieldDescriptor::scalar('color', ScalarType::Int, Nullability::NotNullable), new TextToNumber(OnFailure::Cancel))]));
    }

    public function testAUniqueGroupNeedsAColumnToIndex(): void {
        $this->expectException(InvalidOperation::class);
        $this->expectExceptionMessage('has no column to index');

        $this->save(new AddUniqueGroup('dy:yacht', 'dinghy'));
    }

    public function testAReplacedTypesEntriesKeepTheirIdsAndWhatPointsAtThem(): void {
        $this->save(
            new CreateType('dy:marker'),
            new AddField('dy:marker', FieldDescriptor::scalar('color', ScalarType::String, Nullability::NotNullable), new DefaultValue('white')),
            new AddField('dy:marker', FieldDescriptor::scalar('lit', ScalarType::Bool, Nullability::NotNullable), new DefaultValue(false)),
            new AddField('dy:yacht', FieldDescriptor::reference('mooring', 'dy:buoy'), new DefaultValue(null)),
        );
        $buoy = $this->store('dy:buoy', ['color' => 'green']);
        $yacht = $this->session()->newDynamic('dy:yacht');
        $yacht->set('mooring', $this->session()->loadDynamic($buoy));
        $yachtId = $this->session()->save($yacht);

        $report = $this->saveAs(new StrictPolicy(), new EditorSave(2, [new ReplaceType('dy:buoy', 'dy:marker', ['lit' => new ConstantValue(true)])]));

        self::assertSame(['ReplaceType(dy:buoy → dy:marker)'], $report->fixups);
        self::assertSame('dy:marker', $this->typeOf($buoy));
        $marker = $this->session()->loadDynamic($buoy);
        self::assertSame(['green', true], [$marker->get('color'), $marker->get('lit')], 'A field both have keeps its value; one only the marker has, the initializer fills.');
        self::assertSame($buoy, $this->session()->loadDynamic($yachtId)->get('mooring'), 'The Reference holds the same entity, a marker now.');
        self::assertSame('white', $this->session()->newDynamic('dy:marker')->get('color'), 'The marker keeps its own defaults: the buoy\'s don\'t carry over.');
        self::assertFalse($this->session()->schemas()->has('dy:buoy'));
    }

    public function testAnEditorTypeIsPromotedIntoTheCodesType(): void {
        $craft = $this->craftWithOutboard();
        [$motor] = $this->ownedIds($craft, 'motor');

        $this->save(new ReplaceType('dy:outboard', 'dy:boat_motor'));

        self::assertSame('dy:boat_motor', $this->typeOf($motor), 'Same id, the code\'s type now.');
        $loaded = $this->session()->load($craft);
        self::assertInstanceOf(DyBoatMotor::class, $loaded->motor);
        self::assertSame([300, 3], [$loaded->motor->horsepower, $loaded->motor->propellers], 'What both hold stays; the outboard\'s fuel goes.');
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
            $this->saveAs(new StrictPolicy(),new EditorSave(2, $delete, acceptBreaks: true));
            self::fail('The strict policy blocks a break.');
        } catch (MigrationBlocked) {
        }

        $preview = $this->previewAs(new DeveloperPolicy(),new EditorSave(2, $delete));
        self::assertSame(['mooring'], array_map(static fn (BreakResolution $each): string => (string) $each->break->field, $preview->breaks));
        self::assertFalse($preview->isBlocked());

        try {
            $this->saveAs(new DeveloperPolicy(),new EditorSave(2, $delete));
            self::fail('A break must be accepted.');
        } catch (BreaksNotAccepted $refused) {
            self::assertCount(1, $refused->breaks);
        }

        self::assertSame(2, $this->revision(), 'Nothing ran.');
        $this->saveAs(new DeveloperPolicy(),new EditorSave(2, $delete, acceptBreaks: true));

        self::assertSame(3, $this->revision());
        self::assertFalse($this->session()->schemas()->has('dy:buoy'));
        self::assertArrayNotHasKey('mooring', $this->defaultsOf('dy:yacht'), 'Set aside.');
    }

    public function testAConverterThatCancelsFailsTheSave(): void {
        // The type's own default converts; one buoy's color doesn't.
        $this->save(new SetDefault('dy:buoy', 'color', new DefaultValue('7')));
        $buoy = $this->store('dy:buoy', ['color' => 'red']);
        $this->store('dy:buoy', ['color' => '12']);
        $retype = [new RetypeField('dy:buoy', FieldDescriptor::scalar('color', ScalarType::Int, Nullability::NotNullable), new TextToNumber(OnFailure::Cancel))];

        $preview = $this->previewAs(new StrictPolicy(),new EditorSave(2, $retype));
        self::assertSame([$buoy => 'TextToNumber can\'t convert "red".'], $preview->failures);
        self::assertTrue($preview->isBlocked());

        try {
            $this->saveAs(new StrictPolicy(), new EditorSave(2, $retype));
            self::fail('"red" is no number.');
        } catch (ContentRefused $refused) {
            self::assertSame([$buoy], array_keys($refused->failures));
        }

        self::assertSame(2, $this->revision());
        self::assertSame(PersistenceStatus::Ready, $this->persistence()->status());
        self::assertSame('red', $this->session()->loadDynamic($buoy)->get('color'));
    }

    public function testOnlyTheSchemaEditorRoleSavesOrPreviews(): void {
        // The fixture file holds several classes: load it by its own.
        class_exists(PermissionFixtures::class);
        $persistence = Persistence::boot($this->connection, [new DynamicModule()], schemaEditorRole: 'architect');
        $rename = new EditorSave(1, [new RenameType('dy:buoy', 'dy:marker')]);

        foreach ([static fn () => $persistence->previewSchemas($rename, new PermActor(['editor'])), static fn () => $persistence->saveSchemas($rename, new PermActor(['editor']))] as $refused) {
            try {
                $refused();
                self::fail('An editor isn\'t an architect.');
            } catch (SchemaEditingDenied) {
            }
        }

        $persistence->saveSchemas($rename, new PermActor(['architect']));
        self::assertSame(2, $this->revision());

        $this->expectException(SchemaEditingDenied::class);
        $this->persistence()->previewSchemas(new EditorSave(2, []), new PermActor(['architect']));
    }

    public function testASaveBuiltOnAnOldRevisionIsRefused(): void {
        $this->expectException(StaleSchemaRevision::class);
        $this->expectExceptionMessage('The types changed since revision 0; they are at revision 1 now.');

        $this->saveAs(new StrictPolicy(),new EditorSave(0, [new RenameType('dy:buoy', 'dy:marker')]));
    }

    public function testAnOperationOnANativeTypeIsRefused(): void {
        $this->expectException(InvalidOperation::class);

        $this->save(new RenameType('dy:craft', 'dy:boat'));
    }

    public function testAPreviewChangesNothing(): void {
        $report = $this->previewAs(new StrictPolicy(),new EditorSave(1, [new RenameField('dy:yacht', 'cabins', 'berths')]));

        self::assertSame(['RenameField(dy:yacht, cabins → berths)'], $report->fixups);
        self::assertSame(1, $report->revision);
        self::assertSame(1, $this->revision());
        self::assertArrayHasKey('cabins', $this->defaultsOf('dy:yacht'));
    }
}
