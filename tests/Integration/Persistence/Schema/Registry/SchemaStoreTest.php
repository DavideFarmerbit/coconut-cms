<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Schema\Registry;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

final class SchemaStoreMaxLength implements FieldValidatorRule
{
    public function __construct(
        private readonly int $max,
    ) {
    }

    public function checkUsage(FieldDescriptor $field): void {
    }

    public function toStorage(): array {
        return ['max' => $this->max];
    }

    public static function fromStorage(array $stored): static {
        return new static($stored['max']);
    }

    public function validate(mixed $value): ValidationResult {
        return ValidationResult::valid();
    }
}

#[Entity]
#[Unique('sku')]
final class SchemaStoreProduct
{
    #[Scalar(ScalarType::String)]
    #[FieldPermission(write: 'editor')]
    #[FieldValidator(new SchemaStoreMaxLength(20))]
    private string $sku = '';
}

final class SchemaStoreTest extends TestCase
{
    private Connection $connection;
    private SchemaStore $store;

    protected function setUp(): void {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'user' => 'root',
            'password' => 'root',
            'unix_socket' => '/Users/davidebosco/Library/Application Support/Local/run/PzVL9Ueo_/mysql/mysqld.sock',
            'dbname' => 'local',
        ]);

        $fieldValidators = new FieldValidatorRegistry();
        $fieldValidators->register(Identifier::of('test', 'max-length'), SchemaStoreMaxLength::class);

        $this->store = new SchemaStore($this->connection, new EntitySchemaCodec($fieldValidators, new PrototypeValidatorRegistry(), new ValueObjectRegistry()));
        $this->store->dropTableIfExists();
        $this->store->createTable();
    }

    protected function tearDown(): void {
        $this->store->dropTableIfExists();
        $this->connection->close();
    }

    private static function productSchema(): EntitySchema {
        $blueprint = NativeEntityReader::read(SchemaStoreProduct::class);

        return new EntitySchema(
            identifier: $blueprint->identifier,
            parent: $blueprint->parent,
            table: $blueprint->table,
            editorExtensible: $blueprint->editorExtensible,
            fields: $blueprint->fields,
            fieldPermissions: $blueprint->fieldPermissions,
            fieldValidators: $blueprint->fieldValidators,
            uniqueGroups: $blueprint->uniqueGroups,
            prototypeValidators: $blueprint->prototypeValidators,
        );
    }

    public function testSaveThenLoadRoundTripsASchemaWithItsFingerprints(): void {
        $stored = new StoredSchema(self::productSchema(), str_repeat('a', 64), ['App\\Category' => str_repeat('b', 64)]);

        $this->store->saveAll([$stored]);

        self::assertEquals($stored, $this->store->load(SchemaStoreProduct::class));
    }

    public function testLoadOfAnIdentifierNeverSavedIsNull(): void {
        self::assertNull($this->store->load('App\\NeverSaved'));
    }

    public function testSaveReplacesWhateverWasStoredForTheSameIdentifier(): void {
        $this->store->saveAll([new StoredSchema(self::productSchema(), str_repeat('a', 64), [])]);
        $replacement = new StoredSchema(self::productSchema(), str_repeat('c', 64), []);

        $this->store->saveAll([$replacement]);

        self::assertEquals($replacement, $this->store->load(SchemaStoreProduct::class));
    }
}
