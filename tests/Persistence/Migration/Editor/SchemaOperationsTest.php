<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Editor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetFieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetFieldRules;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetPrototypeRules;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetTypePermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\MaxLength;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Pattern;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin\Range;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultChange;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CreateType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\DeleteType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\InheritDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MakeRequired;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\ReplaceType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\MigratingValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\ConvertsAnyField;
use XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\FillsAnyField;

/** Gives every existing entry 12: what a test's AddField names. */
final readonly class TwelveInitializer implements FieldInitializer
{
    use FillsAnyField;

    public function initialize(Values $values): Conversion {
        return Conversion::of(12);
    }
}

/** Fills nothing: what a test's Reparent names. */
final readonly class NothingToFill implements LevelInitializer
{
    public function initialize(MigratingValues $values): void {
    }
}

/** Keeps every value: what a test's RetypeField names. */
final readonly class SameConverter implements FieldConverter
{
    use ConvertsAnyField;

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        return Conversion::of($old->value());
    }
}

/** Across fields: names two of its type's own fields, so renaming or removing one breaks it. */
final readonly class NamesBothFields implements PrototypeValidatorRule
{
    public function __construct(public string $first, public string $second) {
    }

    public function checkUsage(FieldDescriptorCollection $fields): void {
        foreach ([$this->first, $this->second] as $name) {
            if (!$fields->has(FieldName::of($name))) {
                throw new InvalidArgumentException(sprintf('Unknown field "%s".', $name));
            }
        }
    }

    public function validate(EntityView $entity): ValidationResult {
        return ValidationResult::valid();
    }
}

/**
 * Editor operations on a draft: the definitions they leave, the fixups they
 * give, and what they refuse. A native vehicle allows editor subtypes, a
 * native engine doesn't; the editor's boat extends the vehicle and points
 * at the editor's maker.
 */
final class SchemaOperationsTest extends TestCase
{
    private function draft(): SchemaDraft {
        return new SchemaDraft(
            [
                'shop:vehicle' => self::schema('shop:vehicle', ['name' => self::text('name')], editorExtensible: true),
                'shop:engine' => self::schema('shop:engine', ['power' => self::int('power')]),
            ],
            [
                'shop:boat' => self::schema('shop:boat', [
                    'length' => self::int('length'),
                    'maker' => FieldDescriptor::reference('maker', 'shop:maker'),
                ], parent: 'shop:vehicle', editorExtensible: true),
                'shop:maker' => self::schema('shop:maker', ['title' => self::text('title')], editorExtensible: true),
            ],
        );
    }

    /** $operations applied in order to a fresh draft, which it gives: each checked as a save checks it. */
    private function apply(SchemaOperation ...$operations): SchemaDraft {
        $draft = $this->draft();
        foreach ($operations as $operation) {
            $operation->applyTo($draft);
            $draft->assertRulesFit();
        }

        return $draft;
    }

    /** @return list<string> */
    private static function fixups(SchemaDraft $draft): array {
        return array_map(static fn (Fixup $fixup): string => $fixup->describe(), $draft->fixups());
    }

    /** @param array<string, FieldDescriptor> $fields */
    private static function schema(string $identifier, array $fields, ?string $parent = null, bool $editorExtensible = false): EntitySchema {
        return new EntitySchema(
            identifier: $identifier,
            parent: $parent,
            table: str_replace(':', '_', $identifier),
            editorExtensible: $editorExtensible,
            fields: FieldDescriptorCollection::fromArray($fields),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
        );
    }

    private static function int(string $name, Nullability $nullability = Nullability::Nullable): FieldDescriptor {
        return FieldDescriptor::scalar($name, ScalarType::Int, $nullability);
    }

    private static function text(string $name): FieldDescriptor {
        return FieldDescriptor::scalar($name, ScalarType::String, Nullability::NotNullable);
    }

    /** Asserts that $operation, applied after $before, is refused with a message holding $message. */
    private function assertRefused(string $message, SchemaOperation $operation, SchemaOperation ...$before): void {
        $draft = $this->apply(...$before);
        try {
            $operation->applyTo($draft);
            $draft->assertRulesFit();
            self::fail(sprintf('%s should be refused.', $operation->describe()));
        } catch (InvalidOperation $refused) {
            self::assertStringContainsString($message, $refused->getMessage());
        }
    }

    public function testACreatedTypeGivesNoFixupAndJoinsAsItEndsUp(): void {
        $draft = $this->apply(
            new CreateType('shop:yacht', 'shop:boat'),
            new AddField('shop:yacht', self::int('cabins'), new DefaultValue(2)),
            new RenameField('shop:yacht', 'cabins', 'berths'),
            new RenameType('shop:yacht', 'shop:cruiser'),
        );

        self::assertSame([], self::fixups($draft));
        $cruiser = $draft->created()['shop:cruiser'];
        self::assertSame('shop:boat', $cruiser->parent);
        self::assertSame('shop_cruiser', $cruiser->table);
        self::assertSame(['berths'], array_map('strval', $cruiser->fields->names()));
        self::assertSame(['shop:boat', 'shop:maker', 'shop:cruiser'], array_keys($draft->targets()));
    }

    public function testACreatedTypeDeletedLeavesNothing(): void {
        $draft = $this->apply(new CreateType('shop:yacht'), new DeleteType('shop:yacht'));

        self::assertSame([], self::fixups($draft));
        self::assertSame([], $draft->created());
        self::assertArrayNotHasKey('shop:yacht', $draft->targets());
    }

    public function testCreatingATypeChecksItsNameAndParent(): void {
        $this->assertRefused('already exists', new CreateType('shop:boat'));
        $this->assertRefused('already exists', new CreateType('shop:vehicle'));
        $this->assertRefused('not a "namespace:key"', new CreateType('yacht'));
        $this->assertRefused('not a "namespace:key"', new CreateType('shop:'));
        $this->assertRefused('doesn\'t allow editor-created subtypes', new CreateType('shop:turbo', 'shop:engine'));
        $this->assertRefused('There is no type "shop:nothing"', new CreateType('shop:yacht', 'shop:nothing'));
        $this->assertRefused('named another type when this save started', new CreateType('shop:maker'), new RenameType('shop:maker', 'shop:builder'));
    }

    public function testRenamingAnExistingTypeMovesItsContentAndWhatNamesIt(): void {
        $draft = $this->apply(
            new CreateType('shop:dinghy', 'shop:maker'),
            new RenameType('shop:maker', 'shop:builder'),
        );

        self::assertSame(['RenameType(shop:maker → shop:builder)'], self::fixups($draft));
        $targets = $draft->targets();
        self::assertSame('shop_builder', $targets['shop:builder']->table);
        self::assertSame('shop:builder', $targets['shop:boat']->fields->get(FieldName::of('maker'))->target());
        self::assertSame('shop:builder', $draft->created()['shop:dinghy']->parent);
    }

    public function testDeletingAnExistingTypeDropsItsContent(): void {
        $draft = $this->apply(new DeleteType('shop:maker'));

        self::assertSame(['DropType(shop:maker)'], self::fixups($draft));
        self::assertArrayNotHasKey('shop:maker', $draft->targets());
    }

    public function testNativeTypesAreOnlyRead(): void {
        $this->assertRefused('is a native type', new RenameType('shop:vehicle', 'shop:car'));
        $this->assertRefused('is a native type', new DeleteType('shop:engine'));
        $this->assertRefused('is a native type', new AddField('shop:vehicle', self::int('wheels'), new DefaultValue(4)));
        $this->assertRefused('is a native type', new SetDefault('shop:vehicle', 'name', new DefaultValue('Car')));
    }

    public function testAddingAFieldInitializesExistingEntriesAndPinsItsDefault(): void {
        $draft = $this->apply(new AddField('shop:boat', self::int('masts'), new DefaultValue(1), new TwelveInitializer()));

        self::assertSame([sprintf('AddField(shop:boat, masts, %s)', TwelveInitializer::class)], self::fixups($draft));
        self::assertTrue($draft->targets()['shop:boat']->fields->has(FieldName::of('masts')));
        self::assertEquals([DefaultChange::pin('shop:boat', FieldPath::fromString('masts'), new DefaultValue(1))], $draft->defaultChanges());
    }

    public function testAFieldNameMustBeFreeUpTheChain(): void {
        $this->assertRefused('already has a field "length"', new AddField('shop:boat', self::int('length'), new DefaultValue(0)));
        $this->assertRefused('already has a field "name", from "shop:vehicle"', new AddField('shop:boat', self::text('name'), new DefaultValue('')));
        $this->assertRefused('already has a field "name", from "shop:vehicle"', new RenameField('shop:boat', 'length', 'name'));
    }

    public function testATypeNewInTheSaveTakesNothingForEntriesItDoesntHave(): void {
        $this->assertRefused('no entries to initialize', new AddField('shop:yacht', self::int('cabins'), new DefaultValue(2), new TwelveInitializer()), new CreateType('shop:yacht'));
        $this->assertRefused('no values to convert', new RetypeField('shop:yacht', self::text('cabins'), new SameConverter()), new CreateType('shop:yacht'), new AddField('shop:yacht', self::int('cabins'), new DefaultValue(2)));
    }

    public function testEveryNewFieldButAnEmbedSaysItsDefault(): void {
        $this->assertRefused('needs a default', new AddField('shop:boat', self::int('masts'), null));
        $this->assertRefused('needs a default', new RetypeField('shop:boat', self::text('length')));
        $this->assertRefused('is an Embed', new AddField('shop:boat', FieldDescriptor::embed('hull', 'shop:maker'), new DefaultValue(null)));

        $draft = $this->apply(
            new AddField('shop:boat', FieldDescriptor::embed('hull', 'shop:maker'), null),
            new RetypeField('shop:maker', self::int('title'), default: new DefaultValue(0)),
        );
        self::assertSame(['title'], array_map(static fn (DefaultChange $change): string => (string) $change->path, $draft->defaultChanges()));
    }

    public function testRemovingAndRenamingOwnFieldsMoveTheirContent(): void {
        $draft = $this->apply(new RenameField('shop:boat', 'length', 'size'), new RemoveField('shop:maker', 'title'));

        self::assertSame(['RenameField(shop:boat, length → size)', 'DropField(shop:maker, title)'], self::fixups($draft));
        self::assertSame(['size', 'maker'], array_map('strval', $draft->targets()['shop:boat']->fields->names()));
        self::assertSame([], $draft->targets()['shop:maker']->fields->names());
    }

    public function testOnlyOwnFieldsChange(): void {
        $this->assertRefused('has no field "name" of its own', new RemoveField('shop:boat', 'name'));
        $this->assertRefused('has no field "beam" of its own', new RenameField('shop:boat', 'beam', 'width'));
    }

    public function testMakingAFieldRequiredDropsItsNulls(): void {
        $draft = $this->apply(new MakeRequired('shop:boat', 'length'));

        self::assertSame(['MakeNotNullable(shop:boat, length)'], self::fixups($draft));
        self::assertSame(Nullability::NotNullable, $draft->targets()['shop:boat']->fields->get(FieldName::of('length'))->nullability());
        $this->assertRefused('holds no null to refuse', new MakeRequired('shop:maker', 'title'));
        $this->assertRefused('A Reference is always Nullable', new MakeRequired('shop:boat', 'maker'));
    }

    public function testRetypingConvertsOrResets(): void {
        $draft = $this->apply(
            new RetypeField('shop:boat', self::text('length'), new SameConverter()),
            new RetypeField('shop:maker', self::int('title'), default: new DefaultValue(0)),
        );

        self::assertSame([
            sprintf('RetypeField::converting(shop:boat, length, %s)', SameConverter::class),
            'RetypeField::reset(shop:maker, title)',
        ], self::fixups($draft));
        self::assertSame(ScalarType::String, $draft->targets()['shop:boat']->fields->get(FieldName::of('length'))->scalarType());
        $this->assertRefused('already holds that', new RetypeField('shop:boat', self::int('length')));
    }

    /** The draft with a stored yacht under the boat, holding cabins. */
    private function draftWithYacht(SchemaOperation ...$operations): SchemaDraft {
        $draft = new SchemaDraft(
            [
                'shop:vehicle' => self::schema('shop:vehicle', ['name' => self::text('name')], editorExtensible: true),
                'shop:engine' => self::schema('shop:engine', ['power' => self::int('power')]),
            ],
            [
                'shop:boat' => self::schema('shop:boat', ['length' => self::int('length')], parent: 'shop:vehicle', editorExtensible: true),
                'shop:yacht' => self::schema('shop:yacht', ['cabins' => self::int('cabins')], parent: 'shop:boat', editorExtensible: true),
                'shop:maker' => self::schema('shop:maker', ['title' => self::text('title')], editorExtensible: true),
            ],
        );
        foreach ($operations as $operation) {
            $operation->applyTo($draft);
            $draft->assertRulesFit();
        }

        return $draft;
    }

    public function testAFieldMovesUpOrDownItsChain(): void {
        $up = $this->draftWithYacht(new MoveField('shop:yacht', 'cabins', 'shop:boat', new DefaultValue(0), new TwelveInitializer()));
        self::assertSame([sprintf('MoveField(cabins, shop:yacht → shop:boat, %s)', TwelveInitializer::class)], self::fixups($up));
        self::assertTrue($up->targets()['shop:boat']->fields->has(FieldName::of('cabins')));
        self::assertFalse($up->targets()['shop:yacht']->fields->has(FieldName::of('cabins')));
        self::assertSame(['shop:boat'], array_map(static fn (DefaultChange $change): string => $change->type, $up->defaultChanges()), 'The type declaring it now takes the default.');

        $down = $this->draftWithYacht(new MoveField('shop:boat', 'length', 'shop:yacht', new DefaultValue(5)));
        self::assertSame(['MoveField(length, shop:boat → shop:yacht)'], self::fixups($down));
    }

    public function testAMoveInvolvingANewTypeMovesOnlyWhatExists(): void {
        $intoNew = $this->draftWithYacht(new CreateType('shop:dinghy', 'shop:boat'), new MoveField('shop:boat', 'length', 'shop:dinghy', new DefaultValue(2)));
        self::assertSame(['DropField(shop:boat, length)'], self::fixups($intoNew), 'Only the boat\'s entries existed: they lose it.');

        $outOfNew = $this->draftWithYacht(
            new CreateType('shop:dinghy', 'shop:boat'),
            new AddField('shop:dinghy', self::int('oars'), new DefaultValue(2)),
            new MoveField('shop:dinghy', 'oars', 'shop:boat', new DefaultValue(0), new TwelveInitializer()),
        );
        self::assertSame([sprintf('AddField(shop:boat, oars, %s)', TwelveInitializer::class)], self::fixups($outOfNew), 'Every existing boat gains it.');
    }

    public function testAMoveStaysOnItsChainAndAmongEditorTypes(): void {
        $refusals = [
            ['aren\'t one chain', new MoveField('shop:boat', 'length', 'shop:maker', new DefaultValue(0))],
            ['nobody gains it', new MoveField('shop:boat', 'length', 'shop:yacht', new DefaultValue(0), new TwelveInitializer())],
            ['is a native type', new MoveField('shop:boat', 'length', 'shop:vehicle', new DefaultValue(0))],
            ['needs a default', new MoveField('shop:yacht', 'cabins', 'shop:boat', null)],
            ['has no field "name" of its own', new MoveField('shop:yacht', 'name', 'shop:boat', new DefaultValue(''))],
        ];
        foreach ($refusals as [$message, $operation]) {
            try {
                $this->draftWithYacht($operation);
                self::fail($message);
            } catch (InvalidOperation $refused) {
                self::assertStringContainsString($message, $refused->getMessage());
            }
        }
    }

    public function testATypeMovesUnderAnotherParent(): void {
        $draft = $this->draftWithYacht(new Reparent('shop:yacht', 'shop:maker', new NothingToFill()));
        self::assertSame([sprintf('Reparent(shop:yacht, %s)', NothingToFill::class)], self::fixups($draft));
        self::assertSame('shop:maker', $draft->targets()['shop:yacht']->parent);

        self::assertSame(['Reparent(shop:yacht)'], self::fixups($this->draftWithYacht(new Reparent('shop:yacht', null))));
        self::assertSame([], self::fixups($this->draftWithYacht(new CreateType('shop:dinghy', 'shop:boat'), new Reparent('shop:dinghy', 'shop:maker'))), 'A new type only changes its definition.');
    }

    public function testAReparentNeverLoopsAndRespectsNativeTypes(): void {
        $refusals = [
            ['can\'t extend itself', new Reparent('shop:boat', 'shop:yacht')],
            ['can\'t extend itself', new Reparent('shop:boat', 'shop:boat')],
            ['extends "shop:boat" already', new Reparent('shop:yacht', 'shop:boat')],
            ['doesn\'t allow editor-created subtypes', new Reparent('shop:yacht', 'shop:engine')],
            ['is a native type', new Reparent('shop:vehicle', null)],
        ];
        foreach ($refusals as [$message, $operation]) {
            try {
                $this->draftWithYacht($operation);
                self::fail($message);
            } catch (InvalidOperation $refused) {
                self::assertStringContainsString($message, $refused->getMessage());
            }
        }

        $this->expectExceptionMessage('already has a field "cabins"');
        $this->draftWithYacht(new CreateType('shop:cabin'), new AddField('shop:cabin', self::int('cabins'), new DefaultValue(1)), new Reparent('shop:yacht', 'shop:cabin'));
    }

    public function testDefaultChangesFollowWhatTheSaveDoesAfter(): void {
        $described = static fn (SchemaDraft $draft): array => array_map(static fn (DefaultChange $change): string => $change->type . ' ' . $change->path, $draft->defaultChanges());

        self::assertSame(['shop:hull size', 'shop:yacht size'], $described($this->draftWithYacht(
            new SetDefault('shop:boat', 'length', new DefaultValue(5)),
            new SetDefault('shop:yacht', 'length', new DefaultValue(7)),
            new RenameField('shop:boat', 'length', 'size'),
            new RenameType('shop:boat', 'shop:hull'),
        )), 'A rename reaches what a subtype pins too.');

        self::assertSame([], $described($this->draftWithYacht(
            new SetDefault('shop:yacht', 'cabins', new DefaultValue(2)),
            new RemoveField('shop:yacht', 'cabins'),
        )));

        self::assertSame(['shop:yacht length', 'shop:yacht length'], $described($this->draftWithYacht(
            new SetDefault('shop:boat', 'length', new DefaultValue(5)),
            new SetDefault('shop:yacht', 'length', new DefaultValue(7)),
            new MoveField('shop:boat', 'length', 'shop:yacht', new DefaultValue(9)),
        )), 'Moved down: the boat\'s pin goes; the yacht keeps its own, and the move\'s, applied last, wins.');
    }

    public function testDefaultChangesFollowRenamesAtAnyDepth(): void {
        $described = static fn (SchemaDraft $draft): array => array_map(static fn (DefaultChange $change): string => $change->type . ' ' . $change->path . ($change->pins() ? '=' . json_encode($change->value->value) : ''), $draft->defaultChanges());
        $sail = [
            new CreateType('shop:sail'),
            new AddField('shop:sail', self::int('area'), new DefaultValue(1)),
            new AddField('shop:boat', FieldDescriptor::embed('sail', 'shop:sail'), null),
            new AddField('shop:boat', FieldDescriptor::collection('spares', FieldDescriptor::embed('spares', 'shop:sail')), new DefaultValue([])),
            new AddField('shop:boat', FieldDescriptor::owningReference('builder', 'shop:maker', Nullability::Nullable), new DefaultValue(null)),
        ];

        self::assertSame(
            ['shop:boat sail:size=5', 'shop:boat spares.0:size=3', 'shop:yacht builder.label="Acme"'],
            array_slice($described($this->draftWithYacht(
                ...$sail,
                ...[
                    new SetDefault('shop:boat', 'sail:area', new DefaultValue(5)),
                    new SetDefault('shop:boat', 'spares.0:area', new DefaultValue(3)),
                    new SetDefault('shop:yacht', 'builder.title', new DefaultValue('Acme')),
                    new RenameField('shop:sail', 'area', 'size'),
                    new RenameField('shop:maker', 'title', 'label'),
                ],
            )), -3),
            'Through an Embed, a Collection\'s item and an owned object.',
        );

        self::assertSame([], array_values(array_filter($described($this->draftWithYacht(
            ...$sail,
            ...[new SetDefault('shop:boat', 'sail:area', new DefaultValue(5)), new RemoveField('shop:sail', 'area')],
        )), static fn (string $change): bool => str_contains($change, 'sail:'))), 'Removed deep in the path: the pin goes.');

        $pinned = $described($this->draftWithYacht(
            ...$sail,
            ...[new SetDefault('shop:boat', 'builder', new DefaultValue('shop:maker')), new RenameType('shop:maker', 'shop:builder')],
        ));
        self::assertSame('shop:boat builder="shop:builder"', end($pinned), 'The class an owned slot pins follows its type\'s rename.');
    }

    public function testATypeIsReplacedByAnother(): void {
        $draft = $this->draftWithYacht(new ReplaceType('shop:yacht', 'shop:maker', ['title' => new TwelveInitializer()]));
        self::assertSame(['ReplaceType(shop:yacht → shop:maker)'], self::fixups($draft));
        self::assertArrayNotHasKey('shop:yacht', $draft->targets());

        $subtype = $this->draftWithYacht(new ReplaceType('shop:boat', 'shop:yacht'));
        self::assertSame('shop:vehicle', $subtype->targets()['shop:yacht']->parent, 'A direct subtype takes its place in the chain.');
    }

    public function testAReplacementDeclaresEveryFieldThatDiffers(): void {
        $skiff = [new CreateType('shop:skiff', 'shop:vehicle'), new AddField('shop:skiff', self::text('length'), new DefaultValue(''))];
        $refusals = [
            ['give each a converter, or reset it', [...$skiff, new ReplaceType('shop:boat', 'shop:skiff')]],
            ['nothing to convert or reset', [...$skiff, new ReplaceType('shop:boat', 'shop:skiff', resets: ['length', 'name'])]],
            ['nothing to initialize', [new ReplaceType('shop:yacht', 'shop:maker', ['cabins' => new TwelveInitializer()])]],
            ['only a direct subtype can take its place', [new CreateType('shop:superyacht', 'shop:yacht'), new ReplaceType('shop:boat', 'shop:superyacht')]],
            ['doesn\'t allow editor-created subtypes', [new ReplaceType('shop:boat', 'shop:engine')]],
            ['is a native type', [new ReplaceType('shop:vehicle', 'shop:boat')]],
            ['can\'t replace itself', [new ReplaceType('shop:boat', 'shop:boat')]],
        ];
        foreach ($refusals as [$message, $operations]) {
            try {
                $this->draftWithYacht(...$operations);
                self::fail($message);
            } catch (InvalidOperation $refused) {
                self::assertStringContainsString($message, $refused->getMessage());
            }
        }

        self::assertSame(['ReplaceType(shop:boat → shop:skiff)'], self::fixups($this->draftWithYacht(...$skiff, ...[new ReplaceType('shop:boat', 'shop:skiff', resets: ['length'])])));
    }

    public function testAConverterMustFitTheFieldChange(): void {
        $this->assertRefused(
            'NumberToText converts a Scalar of int, float or decimal into a Scalar of string or text: it doesn\'t fit field "title" of "shop:maker".',
            new RetypeField('shop:maker', self::int('title'), new NumberToText(), new DefaultValue(0)),
        );
        $this->assertRefused('it doesn\'t fit field "length"', new RetypeField('shop:boat', FieldDescriptor::scalar('length', ScalarType::Date, Nullability::Nullable), new NumberToText()));
    }

    public function testDefaultChangesWaitForTheContentToMove(): void {
        $draft = $this->apply(new SetDefault('shop:boat', 'name', new DefaultValue('Boat')), new InheritDefault('shop:boat', 'length'));

        self::assertSame([], self::fixups($draft));
        self::assertSame(['name', 'length'], array_map(static fn (DefaultChange $change): string => (string) $change->path, $draft->defaultChanges()));
        self::assertSame([true, false], array_map(static fn (DefaultChange $change): bool => $change->pins(), $draft->defaultChanges()));
        $this->assertRefused('has no field "beam"', new SetDefault('shop:boat', 'beam', new DefaultValue(3)));
        $this->assertRefused('starts with an index', new InheritDefault('shop:boat', '0'));
    }

    public function testRulesAndPermissionsAreSetWholeAndCleared(): void {
        $set = [
            new SetFieldRules('shop:maker', 'title', new MaxLength(10), new Pattern('^A')),
            new SetFieldPermission('shop:maker', 'title', write: 'editor'),
            new SetTypePermission('shop:maker', read: 'staff'),
            new SetPrototypeRules('shop:boat', new NamesBothFields('length', 'maker')),
        ];
        $maker = $this->apply(...$set)->targets()['shop:maker'];
        self::assertEquals([new MaxLength(10), new Pattern('^A')], $maker->fieldValidators->get(FieldName::of('title')));
        self::assertSame([null, 'editor'], [$maker->fieldPermissions->get(FieldName::of('title'))->read, $maker->fieldPermissions->get(FieldName::of('title'))->write]);
        self::assertSame('staff', $maker->typePermission->read);
        self::assertSame([], self::fixups($this->apply(...$set)), 'Only definitions change.');

        $cleared = $this->apply(...$set, ...[
            new SetFieldRules('shop:maker', 'title'),
            new SetFieldPermission('shop:maker', 'title'),
            new SetTypePermission('shop:maker'),
            new SetPrototypeRules('shop:boat'),
        ])->targets();
        self::assertFalse($cleared['shop:maker']->fieldValidators->has(FieldName::of('title')));
        self::assertFalse($cleared['shop:maker']->fieldPermissions->has(FieldName::of('title')));
        self::assertNull($cleared['shop:maker']->typePermission);
        self::assertSame([], $cleared['shop:boat']->prototypeValidators);

        $this->assertRefused('Rule 0: ' . MaxLength::class . ' checks a Scalar of string or text, and field "length" is not one.', new SetFieldRules('shop:boat', 'length', new MaxLength(3)));
        $this->assertRefused('Rule 1: Unknown field "beam"', new SetPrototypeRules('shop:boat', new NamesBothFields('length', 'maker'), new NamesBothFields('beam', 'length')));
        $this->assertRefused('native type', new SetFieldRules('shop:vehicle', 'name'));
        $this->assertRefused('native type', new SetTypePermission('shop:engine', read: 'staff'));
        $this->assertRefused('has no field "name" of its own', new SetFieldPermission('shop:boat', 'name', read: 'staff'));
    }

    public function testAFieldsRulesAndPermissionFollowIt(): void {
        $set = [new SetFieldRules('shop:maker', 'title', new MaxLength(10)), new SetFieldPermission('shop:maker', 'title', read: 'staff')];

        $renamed = $this->apply(...$set, ...[new RenameField('shop:maker', 'title', 'label')])->targets()['shop:maker'];
        self::assertEquals([new MaxLength(10)], $renamed->fieldValidators->get(FieldName::of('label')));
        self::assertSame('staff', $renamed->fieldPermissions->get(FieldName::of('label'))->read);
        self::assertFalse($renamed->fieldValidators->has(FieldName::of('title')));

        $removed = $this->apply(...$set, ...[new RemoveField('shop:maker', 'title')])->targets()['shop:maker'];
        self::assertSame(0, count($removed->fieldValidators) + count($removed->fieldPermissions));

        $retyped = $this->apply(...$set, ...[new RetypeField('shop:maker', self::int('title'), default: new DefaultValue(0)), new SetFieldRules('shop:maker', 'title', new Range(max: 5))])->targets()['shop:maker'];
        self::assertEquals([new Range(max: 5)], $retyped->fieldValidators->get(FieldName::of('title')), 'A retype clears the rules; new ones fit the new field.');
        self::assertSame('staff', $retyped->fieldPermissions->get(FieldName::of('title'))->read, 'A permission doesn\'t depend on what the field holds.');

        $moved = $this->draftWithYacht(
            new SetFieldRules('shop:yacht', 'cabins', new Range(min: 0)),
            new SetFieldPermission('shop:yacht', 'cabins', write: 'captain'),
            new MoveField('shop:yacht', 'cabins', 'shop:boat', new DefaultValue(0)),
        )->targets();
        self::assertEquals([new Range(min: 0)], $moved['shop:boat']->fieldValidators->get(FieldName::of('cabins')));
        self::assertSame('captain', $moved['shop:boat']->fieldPermissions->get(FieldName::of('cabins'))->write);
        self::assertSame(0, count($moved['shop:yacht']->fieldValidators) + count($moved['shop:yacht']->fieldPermissions));
    }

    public function testAnOperationBreakingACrossFieldRuleIsRefusedUntilTheRuleIsSetAnew(): void {
        $rule = new SetPrototypeRules('shop:boat', new NamesBothFields('length', 'maker'));

        $this->assertRefused('It breaks a rule of "shop:boat": Unknown field "length". Set its rules anew in this save.', new RenameField('shop:boat', 'length', 'size'), $rule);
        $this->assertRefused('It breaks a rule of "shop:boat"', new RemoveField('shop:boat', 'maker'), $rule);

        $fixed = $this->apply($rule, new SetPrototypeRules('shop:boat'), new RenameField('shop:boat', 'length', 'size'), new SetPrototypeRules('shop:boat', new NamesBothFields('size', 'maker')));
        self::assertEquals([new NamesBothFields('size', 'maker')], $fixed->targets()['shop:boat']->prototypeValidators);
    }
}
