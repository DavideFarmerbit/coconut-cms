<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Diff;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\FieldAdded;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\FieldChanged;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\FieldRemoved;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\ParentChanged;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\SchemaDiff;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\TableChanged;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\TypeAdded;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\TypeRemoved;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroupAdded;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroupRemoved;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

final class SchemaDiffTest extends TestCase
{
    /**
     * @param array<string, FieldDescriptor> $fields
     * @param list<UniqueGroup> $uniqueGroups
     */
    private static function schema(string $identifier, array $fields = [], ?string $parent = null, ?string $table = null, array $uniqueGroups = [], bool $editorExtensible = false): EntitySchema {
        return new EntitySchema(
            identifier: $identifier,
            parent: $parent,
            table: $table ?? str_replace(':', '_', $identifier),
            editorExtensible: $editorExtensible,
            fields: FieldDescriptorCollection::fromArray($fields),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: $uniqueGroups,
            prototypeValidators: [],
        );
    }

    private static function int(string $name): FieldDescriptor {
        return FieldDescriptor::scalar($name, ScalarType::Int, Nullability::Nullable);
    }

    /**
     * @param array<string, EntitySchema> $stored
     * @param array<string, EntitySchema> $code
     * @return list<string> Each difference's description, in order.
     */
    private static function described(array $stored, array $code): array {
        return array_map(static fn (Difference $difference): string => $difference->describe(), SchemaDiff::between($stored, $code));
    }

    public function testIdenticalSchemasDifferInNothing(): void {
        $car = self::schema('test:car', ['speed' => self::int('speed')]);

        self::assertSame([], SchemaDiff::between(['test:car' => $car], ['test:car' => self::schema('test:car', ['speed' => self::int('speed')])]));
    }

    public function testATypeOnlyInCodeIsAddedAndOneOnlyStoredIsRemoved(): void {
        $differences = SchemaDiff::between(['test:old' => self::schema('test:old')], ['test:new' => self::schema('test:new')]);

        self::assertEquals([new TypeAdded('test:new'), new TypeRemoved('test:old')], $differences);
    }

    public function testAFieldOnlyInCodeIsAddedOneOnlyStoredIsRemovedAndOneDeclaredDifferentlyChanged(): void {
        $stored = self::schema('test:car', ['speed' => self::int('speed'), 'price' => self::int('price')]);
        $code = self::schema('test:car', ['speed' => FieldDescriptor::scalar('speed', ScalarType::Float, Nullability::Nullable), 'cost' => self::int('cost')]);

        self::assertEquals([
            new FieldChanged('test:car', FieldName::of('speed'), self::int('speed'), FieldDescriptor::scalar('speed', ScalarType::Float, Nullability::Nullable)),
            new FieldAdded('test:car', FieldName::of('cost'), self::int('cost')),
            new FieldRemoved('test:car', FieldName::of('price'), self::int('price')),
        ], SchemaDiff::between(['test:car' => $stored], ['test:car' => $code]));
    }

    public function testAFieldBecomingNullableIsNoDifference(): void {
        $notNullable = FieldDescriptor::scalar('speed', ScalarType::Int, Nullability::NotNullable);
        $items = static fn (Nullability $nullability): FieldDescriptor => FieldDescriptor::collection('sizes', FieldDescriptor::scalar('sizes', ScalarType::Int, $nullability));

        self::assertSame([], SchemaDiff::between(
            ['test:car' => self::schema('test:car', ['speed' => $notNullable, 'sizes' => $items(Nullability::NotNullable)])],
            ['test:car' => self::schema('test:car', ['speed' => self::int('speed'), 'sizes' => $items(Nullability::Nullable)])],
        ));
    }

    public function testAFieldThatCanNoLongerHoldNullHasChangedAndSaysSo(): void {
        $stored = self::schema('test:car', ['speed' => self::int('speed')]);
        $code = self::schema('test:car', ['speed' => FieldDescriptor::scalar('speed', ScalarType::Int, Nullability::NotNullable)]);

        $differences = SchemaDiff::between(['test:car' => $stored], ['test:car' => $code]);

        self::assertInstanceOf(FieldChanged::class, $differences[0]);
        self::assertTrue($differences[0]->onlyNarrows());
        self::assertSame('Field "speed" of type "test:car" can no longer hold null.', $differences[0]->describe());
    }

    public function testNarrowingAlongWithAnotherChangeIsNotOnlyNarrowing(): void {
        $stored = self::schema('test:car', ['speed' => self::int('speed')]);
        $code = self::schema('test:car', ['speed' => FieldDescriptor::scalar('speed', ScalarType::Float, Nullability::NotNullable)]);

        $differences = SchemaDiff::between(['test:car' => $stored], ['test:car' => $code]);

        self::assertFalse($differences[0]->onlyNarrows());
    }

    public function testAFieldTargetingAnotherTypeHasChanged(): void {
        $stored = self::schema('test:car', ['engine' => FieldDescriptor::owningReference('engine', 'test:engine', Nullability::Nullable)]);
        $code = self::schema('test:car', ['engine' => FieldDescriptor::owningReference('engine', 'test:motor', Nullability::Nullable)]);

        self::assertSame(['Field "engine" of type "test:car" changed what it holds.'], self::described(['test:car' => $stored], ['test:car' => $code]));
    }

    public function testAParentAndATableChangeAreDifferences(): void {
        $stored = self::schema('test:car', parent: null, table: 'cars');
        $code = self::schema('test:car', parent: 'test:vehicle', table: 'car');

        self::assertEquals(
            [new ParentChanged('test:car', null, 'test:vehicle'), new TableChanged('test:car', 'cars', 'car')],
            SchemaDiff::between(['test:car' => $stored], ['test:car' => $code]),
        );
    }

    public function testAUniqueGroupIsItsSetOfFieldsWhateverTheirOrder(): void {
        $fields = ['a' => self::int('a'), 'b' => self::int('b'), 'c' => self::int('c')];
        $stored = self::schema('test:car', $fields, uniqueGroups: [UniqueGroup::of(FieldName::of('a'), FieldName::of('b')), UniqueGroup::of(FieldName::of('c'))]);
        $code = self::schema('test:car', $fields, uniqueGroups: [UniqueGroup::of(FieldName::of('b'), FieldName::of('a')), UniqueGroup::of(FieldName::of('a'), FieldName::of('c'))]);

        self::assertEquals([
            new UniqueGroupAdded('test:car', UniqueGroup::of(FieldName::of('a'), FieldName::of('c'))),
            new UniqueGroupRemoved('test:car', UniqueGroup::of(FieldName::of('c'))),
        ], SchemaDiff::between(['test:car' => $stored], ['test:car' => $code]));
    }

    public function testEditorExtensibleIsNeverADifference(): void {
        $stored = self::schema('test:car', editorExtensible: false);
        $code = self::schema('test:car', editorExtensible: true);

        self::assertSame([], SchemaDiff::between(['test:car' => $stored], ['test:car' => $code]));
    }

    public function testEveryDifferenceNamesItself(): void {
        $stored = self::schema('test:car', ['price' => self::int('price')], table: 'cars');
        $code = self::schema('test:car', ['cost' => self::int('cost')], parent: 'test:vehicle', table: 'car', uniqueGroups: [UniqueGroup::of(FieldName::of('cost'))]);

        self::assertSame([
            'Type "test:car" changed its parent from none to "test:vehicle".',
            'Type "test:car" moved its table from "cars" to "car".',
            'Field "cost" was added to type "test:car".',
            'Field "price" was removed from type "test:car".',
            'Type "test:car" gained a unique group over "cost".',
        ], self::described(['test:car' => $stored], ['test:car' => $code]));
    }
}
