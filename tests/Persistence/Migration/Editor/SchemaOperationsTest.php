<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Editor;

use PHPUnit\Framework\TestCase;
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

/** Gives every existing entry 12: what a test's AddField names. */
final readonly class TwelveInitializer implements FieldInitializer
{
    public function initialize(Values $values): Conversion {
        return Conversion::of(12);
    }
}

/** Keeps every value: what a test's RetypeField names. */
final readonly class SameConverter implements FieldConverter
{
    public function convert(Captured $old): Conversion {
        return Conversion::of($old->value());
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

    /** $operations applied in order to a fresh draft, which it gives. */
    private function apply(SchemaOperation ...$operations): SchemaDraft {
        $draft = $this->draft();
        foreach ($operations as $operation) {
            $operation->applyTo($draft);
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
            self::fail(sprintf('%s should be refused.', $operation->describe()));
        } catch (InvalidOperation $refused) {
            self::assertStringContainsString($message, $refused->getMessage());
        }
    }

    public function testACreatedTypeGivesNoFixupAndJoinsAsItEndsUp(): void {
        $draft = $this->apply(
            new CreateType('shop:yacht', 'shop:boat'),
            new AddField('shop:yacht', self::int('cabins')),
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
        $this->assertRefused('is a native type', new AddField('shop:vehicle', self::int('wheels')));
        $this->assertRefused('is a native type', new SetDefault('shop:vehicle', 'name', new DefaultValue('Car')));
    }

    public function testAddingAFieldInitializesExistingEntriesAndPinsItsDefault(): void {
        $draft = $this->apply(new AddField('shop:boat', self::int('masts'), new TwelveInitializer(), new DefaultValue(1)));

        self::assertSame([sprintf('AddField(shop:boat, masts, %s)', TwelveInitializer::class)], self::fixups($draft));
        self::assertTrue($draft->targets()['shop:boat']->fields->has(FieldName::of('masts')));
        self::assertEquals([DefaultChange::pin('shop:boat', FieldPath::fromString('masts'), new DefaultValue(1))], $draft->defaultChanges());
    }

    public function testAFieldNameMustBeFreeUpTheChain(): void {
        $this->assertRefused('already has a field "length"', new AddField('shop:boat', self::int('length')));
        $this->assertRefused('already has a field "name", from "shop:vehicle"', new AddField('shop:boat', self::text('name')));
        $this->assertRefused('already has a field "name", from "shop:vehicle"', new RenameField('shop:boat', 'length', 'name'));
    }

    public function testATypeNewInTheSaveTakesNothingForEntriesItDoesntHave(): void {
        $this->assertRefused('no entries to initialize', new AddField('shop:yacht', self::int('cabins'), new TwelveInitializer()), new CreateType('shop:yacht'));
        $this->assertRefused('no values to convert', new RetypeField('shop:yacht', self::text('cabins'), new SameConverter()), new CreateType('shop:yacht'), new AddField('shop:yacht', self::int('cabins')));
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
            new RetypeField('shop:maker', self::int('title')),
        );

        self::assertSame([
            sprintf('RetypeField::converting(shop:boat, length, %s)', SameConverter::class),
            'RetypeField::reset(shop:maker, title)',
        ], self::fixups($draft));
        self::assertSame(ScalarType::String, $draft->targets()['shop:boat']->fields->get(FieldName::of('length'))->scalarType());
        $this->assertRefused('already holds that', new RetypeField('shop:boat', self::int('length')));
    }

    public function testDefaultChangesWaitForTheContentToMove(): void {
        $draft = $this->apply(new SetDefault('shop:boat', 'name', new DefaultValue('Boat')), new InheritDefault('shop:boat', 'length'));

        self::assertSame([], self::fixups($draft));
        self::assertSame(['name', 'length'], array_map(static fn (DefaultChange $change): string => (string) $change->path, $draft->defaultChanges()));
        self::assertSame([true, false], array_map(static fn (DefaultChange $change): bool => $change->pins(), $draft->defaultChanges()));
        $this->assertRefused('has no field "beam"', new SetDefault('shop:boat', 'beam', new DefaultValue(3)));
        $this->assertRefused('starts with an index', new InheritDefault('shop:boat', '0'));
    }
}
