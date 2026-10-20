<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Registry;

use function Sodium\add;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

final class SchemaRegistryTest extends TestCase
{
    private static function emptySchema(string $identifier): EntitySchema {
        return new EntitySchema(
            identifier: $identifier,
            parent: null,
            table: 'whatever',
            editorExtensible: false,
            fields: FieldDescriptorCollection::empty(),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
        );
    }

    public function testHasIsFalseForAnIdentifierNeverRegistered(): void {
        $registry = new SchemaRegistry();

        self::assertFalse($registry->has('Never\\Registered'));
    }

    public function testRegisterThenHasAndGetFindTheSameSchema(): void {
        $registry = new SchemaRegistry();
        $schema = self::emptySchema('App\\Product');

        $registry->register($schema);

        self::assertTrue($registry->has('App\\Product'));
        self::assertSame($schema, $registry->get('App\\Product'));
    }

    public function testGetOnAnUnregisteredIdentifierThrows(): void {
        $registry = new SchemaRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->get('Never\\Registered');
    }

    public function testRegisterAgainReplacesWhateverWasThereForTheSameIdentifier(): void {
        $registry = new SchemaRegistry();
        $registry->register(self::emptySchema('App\\Product'));

        $replacement = self::emptySchema('App\\Product');
        $registry->register($replacement);

        self::assertSame($replacement, $registry->get('App\\Product'));
    }
}
