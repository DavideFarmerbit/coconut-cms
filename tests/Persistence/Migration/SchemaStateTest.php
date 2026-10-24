<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

final class SchemaStateTest extends TestCase
{
    /** @param array<string, FieldDescriptor> $fields */
    private static function stored(string $identifier, array $fields, ?string $parent = null): StoredSchema {
        return new StoredSchema(new EntitySchema(
            identifier: $identifier,
            parent: $parent,
            table: str_replace(':', '_', $identifier),
            editorExtensible: false,
            fields: FieldDescriptorCollection::fromArray($fields),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
        ), SchemaSource::Native, str_repeat('0', 64), []);
    }

    private static function int(string $name): FieldDescriptor {
        return FieldDescriptor::scalar($name, ScalarType::Int, Nullability::Nullable);
    }

    /** A car with a chassis Embed, an owned engine that may be a turbo, Embed wheels, owned spares and tags. */
    private static function schemas(): SchemaState {
        return SchemaState::of([
            self::stored('test:chassis', ['weight' => self::int('weight')]),
            self::stored('test:wheel', ['size' => self::int('size')]),
            self::stored('test:engine', ['horsepower' => self::int('horsepower')]),
            self::stored('test:turbo_engine', ['boost' => self::int('boost')], parent: 'test:engine'),
            self::stored('test:car', [
                'price' => self::int('price'),
                'chassis' => FieldDescriptor::embed('chassis', 'test:chassis'),
                'engine' => FieldDescriptor::owningReference('engine', 'test:engine', Nullability::Nullable),
                'wheels' => FieldDescriptor::collection('wheels', FieldDescriptor::embed('wheels', 'test:wheel')),
                'spares' => FieldDescriptor::collection('spares', FieldDescriptor::owningReference('spares', 'test:engine', Nullability::Nullable)),
                'tags' => FieldDescriptor::collection('tags', FieldDescriptor::scalar('tags', ScalarType::String, Nullability::NotNullable)),
            ]),
        ], []);
    }

    /** @return array<string, array{string}> */
    public static function existingPaths(): array {
        return [
            'a field' => ['price'],
            'into an Embed' => ['chassis:weight'],
            'an owned slot' => ['engine'],
            'into an owned object' => ['engine.horsepower'],
            'a field only a subtype has' => ['engine.boost'],
            'a Collection' => ['spares'],
            'an item' => ['spares.0'],
            'into an owned item' => ['spares.1.boost'],
            'into an Embed item' => ['wheels.0.size'],
            'a scalar item' => ['tags.2'],
        ];
    }

    #[DataProvider('existingPaths')]
    public function testAPathLeadingSomewherePasses(string $path): void {
        self::schemas()->assertPath('test:car', FieldPath::fromString($path));
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function missingPaths(): array {
        return [
            'a typo' => ['prise'],
            'a field of another type' => ['horsepower'],
            'into an Embed with "."' => ['chassis.weight'],
            'into an owned object with ":"' => ['engine:horsepower'],
            'under a scalar' => ['price.amount'],
            'a field the Embed lacks' => ['chassis:size'],
            'a field past an item without its index' => ['spares.horsepower'],
            'under a scalar item' => ['tags.0.length'],
        ];
    }

    #[DataProvider('missingPaths')]
    public function testAPathLeadingNowhereThrows(string $path): void {
        $this->expectException(InvalidFixup::class);
        $this->expectExceptionMessage(sprintf('type "test:car" has no path "%s" at this point.', $path));

        self::schemas()->assertPath('test:car', FieldPath::fromString($path));
    }
}
