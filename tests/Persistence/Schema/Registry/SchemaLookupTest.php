<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Registry;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

final class SchemaLookupTest extends TestCase
{
    /** @param string[] $fieldNames */
    private static function schema(string $identifier, ?string $parent, array $fieldNames): EntitySchema {
        $fields = FieldDescriptorCollection::empty();
        foreach ($fieldNames as $name) {
            $fields = $fields->with(FieldName::of($name), FieldDescriptor::scalar($name, ScalarType::String, Nullability::Nullable));
        }

        return new EntitySchema(
            identifier: $identifier,
            parent: $parent,
            table: 'whatever',
            editorExtensible: false,
            fields: $fields,
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
        );
    }

    public function testTheBatchWinsOverAnOlderRegistration(): void {
        $registry = (new InMemorySchemaStores())->registry;
        $registry->register(self::schema('Engine', null, ['old']));
        $fresh = self::schema('Engine', null, ['horsepower']);

        $lookup = new SchemaLookup($registry, ['Engine' => $fresh]);

        self::assertSame($fresh, $lookup->get('Engine'));
    }

    public function testFallsBackToTheRegistryForAnythingOutsideTheBatch(): void {
        $registry = (new InMemorySchemaStores())->registry;
        $registered = self::schema('Piston', null, ['diameter']);
        $registry->register($registered);

        $lookup = new SchemaLookup($registry, ['Engine' => self::schema('Engine', null, [])]);

        self::assertSame($registered, $lookup->get('Piston'));
        self::assertTrue($lookup->has('Piston'));
        self::assertTrue($lookup->has('Engine'));
        self::assertFalse($lookup->has('Wheel'));
    }

    public function testGetOnAnUnknownIdentifierThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        (new SchemaLookup((new InMemorySchemaStores())->registry))->get('Wheel');
    }

    public function testHierarchyFieldsWalkParentsAcrossTheBatchAndTheRegistry(): void {
        $registry = (new InMemorySchemaStores())->registry;
        $registry->register(self::schema('Engine', null, ['horsepower']));

        $lookup = new SchemaLookup($registry, ['SportsEngine' => self::schema('SportsEngine', 'Engine', ['boost'])]);

        $names = array_map('strval', $lookup->hierarchyFieldsOf('SportsEngine')->names());
        self::assertSame(['horsepower', 'boost'], $names);
    }
}
