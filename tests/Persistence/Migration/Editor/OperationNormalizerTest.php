<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Editor;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Normalize\OperationNormalizer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CreateType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\DeleteType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\InheritDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\ConstantValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MakeRequired;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

/**
 * The normalizer over the same types as the operation tests: a native
 * vehicle, the editor's boat extending it with a length that defaults to 10,
 * and the editor's maker, titled "Acme" by default.
 */
final class OperationNormalizerTest extends TestCase
{
    /** @return list<SchemaOperation> $operations normalized. */
    private static function normalized(SchemaOperation ...$operations): array {
        return (new OperationNormalizer(
            ['shop:vehicle' => self::schema('shop:vehicle', ['name' => self::text('name')], editorExtensible: true)],
            [
                'shop:boat' => self::schema('shop:boat', ['length' => self::int('length'), 'maker' => FieldDescriptor::reference('maker', 'shop:maker')], 'shop:vehicle'),
                'shop:maker' => self::schema('shop:maker', ['title' => self::text('title')]),
            ],
            ['shop:boat' => ['length' => 10], 'shop:maker' => ['title' => 'Acme']],
        ))->normalize(array_values($operations));
    }

    /** @return list<string> $operations normalized, described. */
    private static function described(SchemaOperation ...$operations): array {
        return array_map(static fn (SchemaOperation $operation): string => $operation->describe(), self::normalized(...$operations));
    }

    /** @param array<string, FieldDescriptor> $fields */
    private static function schema(string $identifier, array $fields, ?string $parent = null, bool $editorExtensible = true): EntitySchema {
        return new EntitySchema($identifier, $parent, str_replace(':', '_', $identifier), $editorExtensible, FieldDescriptorCollection::fromArray($fields), FieldPermissionCollection::empty(), FieldValidatorCollection::empty(), [], []);
    }

    private static function int(string $name): FieldDescriptor {
        return FieldDescriptor::scalar($name, ScalarType::Int, Nullability::Nullable);
    }

    private static function text(string $name): FieldDescriptor {
        return FieldDescriptor::scalar($name, ScalarType::String, Nullability::NotNullable);
    }

    public function testRenamesChainAndOneBackIsNone(): void {
        self::assertSame(['RenameField(shop:boat, length → beam)'], self::described(new RenameField('shop:boat', 'length', 'size'), new RenameField('shop:boat', 'size', 'beam')));
        self::assertSame([], self::described(new RenameField('shop:boat', 'length', 'size'), new RenameField('shop:boat', 'size', 'length')));
        self::assertSame(['RenameType(shop:maker → shop:yard)'], self::described(new RenameType('shop:maker', 'shop:builder'), new RenameType('shop:builder', 'shop:yard')));
        self::assertSame([], self::described(new RenameType('shop:maker', 'shop:builder'), new RenameType('shop:builder', 'shop:maker')));
    }

    public function testANewTypeTakesInWhatsDoneToIt(): void {
        $operations = self::normalized(
            new CreateType('shop:yacht', 'shop:boat'),
            new AddField('shop:yacht', self::int('cabins'), new DefaultValue(2)),
            new RenameField('shop:yacht', 'cabins', 'berths'),
            new RetypeField('shop:yacht', self::text('berths'), default: new DefaultValue('2')),
            new SetDefault('shop:yacht', 'berths', new DefaultValue('3')),
            new RenameType('shop:yacht', 'shop:cruiser'),
        );

        self::assertSame(['CreateType(shop:cruiser extends shop:boat)', 'AddField(shop:cruiser, berths)'], array_map(static fn (SchemaOperation $operation): string => $operation->describe(), $operations));
        self::assertInstanceOf(AddField::class, $operations[1]);
        self::assertSame(ScalarType::String, $operations[1]->field->scalarType());
        self::assertSame('3', $operations[1]->default->value);
    }

    public function testWhatsAddedThenTakenAwayIsNothing(): void {
        self::assertSame([], self::described(new CreateType('shop:yacht'), new AddField('shop:yacht', self::int('cabins'), new DefaultValue(2)), new DeleteType('shop:yacht')));
        self::assertSame([], self::described(new AddField('shop:boat', self::int('masts'), new DefaultValue(1)), new RemoveField('shop:boat', 'masts')));
    }

    public function testAStoredFieldAddedBackKeepsItsValuesOrIsReset(): void {
        self::assertSame([], self::described(new RemoveField('shop:boat', 'length'), new AddField('shop:boat', self::int('length'), new DefaultValue(10))));
        self::assertSame(['RetypeField(shop:boat, length, reset)'], self::described(new RemoveField('shop:boat', 'length'), new AddField('shop:boat', self::text('length'), new DefaultValue(''))));
        self::assertSame(['RemoveField(shop:boat, length)'], self::described(new RenameField('shop:boat', 'length', 'size'), new RemoveField('shop:boat', 'size')));
    }

    public function testAFieldRetypedBackHoldsWhatItHeld(): void {
        self::assertSame([], self::described(new RetypeField('shop:boat', self::text('length'), default: new DefaultValue('')), new RetypeField('shop:boat', self::int('length'), default: new DefaultValue(10))));
        self::assertSame(['MakeRequired(shop:boat, length)'], self::described(new RenameField('shop:boat', 'length', 'size'), new RenameField('shop:boat', 'size', 'length'), new MakeRequired('shop:boat', 'length')));
    }

    public function testADefaultKeepsItsLastChangeUnderTheFinalNames(): void {
        self::assertSame(
            ['RenameField(shop:boat, length → size)', 'SetDefault(shop:boat, size)'],
            self::described(new SetDefault('shop:boat', 'length', new DefaultValue(5)), new RenameField('shop:boat', 'length', 'size'), new SetDefault('shop:boat', 'size', new DefaultValue(7))),
        );
        self::assertSame(7, self::normalized(new SetDefault('shop:boat', 'length', new DefaultValue(5)), new SetDefault('shop:boat', 'length', new DefaultValue(7)))[0]->value->value);
        self::assertSame(['SetDefault(shop:boat, name)'], self::described(new SetDefault('shop:boat', 'name', new DefaultValue('Boat'))));
    }

    public function testADefaultBackToWhatsStoredIsNone(): void {
        self::assertSame([], self::described(new SetDefault('shop:boat', 'length', new DefaultValue(5)), new SetDefault('shop:boat', 'length', new DefaultValue(10))));
        self::assertSame([], self::described(new InheritDefault('shop:boat', 'name')));
        self::assertSame(['InheritDefault(shop:boat, length)'], self::described(new InheritDefault('shop:boat', 'length')));
    }

    public function testEachOperationFindsWhatItNames(): void {
        self::assertSame(
            ['DeleteType(shop:maker)', 'RenameType(shop:boat → shop:maker)'],
            self::described(new RenameType('shop:boat', 'shop:hull'), new DeleteType('shop:maker'), new RenameType('shop:hull', 'shop:maker')),
        );
        self::assertSame(
            ['RenameType(shop:maker → shop:builder)', 'RenameType(shop:boat → shop:maker)'],
            self::described(new RenameType('shop:maker', 'shop:builder'), new RenameType('shop:boat', 'shop:maker')),
        );
    }

    /** @return list<string> $operations normalized over the boat with a stored yacht under it, holding cabins, described. */
    private static function withYacht(SchemaOperation ...$operations): array {
        $normalized = (new OperationNormalizer(
            ['shop:vehicle' => self::schema('shop:vehicle', ['name' => self::text('name')])],
            [
                'shop:boat' => self::schema('shop:boat', ['length' => self::int('length')], 'shop:vehicle'),
                'shop:yacht' => self::schema('shop:yacht', ['cabins' => self::int('cabins')], 'shop:boat'),
            ],
            [],
        ))->normalize(array_values($operations));

        return array_map(static fn (SchemaOperation $operation): string => $operation->describe(), $normalized);
    }

    public function testAFieldMovedSeveralTimesMovesOnceFromWhereItWas(): void {
        self::assertSame([], self::withYacht(new MoveField('shop:yacht', 'cabins', 'shop:boat', new DefaultValue(0)), new MoveField('shop:boat', 'cabins', 'shop:yacht', new DefaultValue(0))));
        self::assertSame(
            ['RenameField(shop:yacht, cabins → berths)', 'MoveField(berths, shop:yacht → shop:boat)', 'RetypeField(shop:boat, berths, reset)'],
            self::withYacht(
                new MoveField('shop:yacht', 'cabins', 'shop:boat', new DefaultValue(0)),
                new RenameField('shop:boat', 'cabins', 'berths'),
                new RetypeField('shop:boat', self::text('berths'), default: new DefaultValue('')),
            ),
        );
    }

    public function testAFieldAddedThenMovedIsAddedWhereItEnds(): void {
        self::assertSame(['AddField(shop:boat, masts)'], self::withYacht(
            new AddField('shop:yacht', self::int('masts'), new DefaultValue(1)),
            new MoveField('shop:yacht', 'masts', 'shop:boat', new DefaultValue(2)),
        ));
    }

    public function testATypeReparentedSeveralTimesMovesOnce(): void {
        self::assertSame(['Reparent(shop:yacht under shop:vehicle)'], self::withYacht(new Reparent('shop:yacht', null), new Reparent('shop:yacht', 'shop:vehicle')));
        self::assertSame([], self::withYacht(new Reparent('shop:yacht', null), new Reparent('shop:yacht', 'shop:boat')));
        self::assertSame(
            ['CreateType(shop:dinghy extends shop:vehicle)'],
            self::withYacht(new CreateType('shop:dinghy', 'shop:boat'), new Reparent('shop:dinghy', 'shop:vehicle')),
            'A new type is created where it ends.',
        );
    }

    public function testMovesWithInitializersDontFold(): void {
        $this->expectException(InvalidOperation::class);
        $this->expectExceptionMessage('moves more than once, with an initializer');

        self::withYacht(
            new MoveField('shop:boat', 'length', 'shop:yacht', new DefaultValue(0)),
            new MoveField('shop:yacht', 'length', 'shop:boat', new DefaultValue(0), new ConstantValue(4)),
        );
    }

    public function testSwappedNamesAreRefused(): void {
        $this->expectException(InvalidOperation::class);
        $this->expectExceptionMessage('swap names');

        self::normalized(new RenameType('shop:boat', 'shop:hull'), new RenameType('shop:maker', 'shop:boat'), new RenameType('shop:hull', 'shop:maker'));
    }

    public function testAnOperationThatCantApplyIsRefusedFirst(): void {
        $this->expectException(InvalidOperation::class);
        $this->expectExceptionMessage('has no field "beam" of its own');

        self::normalized(new RemoveField('shop:boat', 'beam'));
    }
}
