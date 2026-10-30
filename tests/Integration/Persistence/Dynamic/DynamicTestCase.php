<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration\MigratorEditorTypes;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/**
 * A database holding the dynamic entity tests' native types and the
 * editor-created types built on them, with what the tests store and check.
 */
abstract class DynamicTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture files hold several classes: load them by their own.
        class_exists(DynamicFixtures::class);
        class_exists(MigratorEditorTypes::class);

        Persistence::boot($this->connection, [new DynamicModule()])->migrate();
        [$schemas, $values, $registry] = $this->stores();
        MigratorEditorTypes::create($this->connection, $schemas, $values, $registry, DynamicFixtures::editorTypes());
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** @return array{SchemaStore, SchemaFieldValueStore, SchemaRegistry} What a new request starts with. */
    protected function stores(): array {
        $codec = new EntitySchemaCodec(new FieldValidatorRegistry(), new PrototypeValidatorRegistry(), new ValueObjectRegistry(), new EnumRegistry());
        $schemas = new SchemaStore($this->connection, $codec);

        return [$schemas, new SchemaFieldValueStore($this->connection), new SchemaRegistry($schemas)];
    }

    /** A session of a new request, as the system. */
    protected function session(): PersistenceSession {
        return Persistence::boot($this->connection, [new DynamicModule()])->as(new SystemActor());
    }

    /**
     * Stores a $type entity holding $set, every other value its default, the
     * way a save writes values.
     * @param array<string, mixed> $set Keyed by path, in stored form.
     */
    protected function store(string $type, array $set): string {
        [, $values, $registry] = $this->stores();

        return MigratorEditorTypes::save($this->connection, $values, $registry, $type, $set);
    }

    /** A craft with an outboard motor: an editor-created entity in a native property. */
    protected function craftWithOutboard(): string {
        return $this->store('dy:craft', ['name' => 'Tender', 'motor' => 'dy:outboard', 'motor.horsepower' => 300, 'motor.propellers' => 3, 'motor.fuel' => 'diesel']);
    }

    /**
     * The values of an outboard in the Collection $field, item $index, burning $fuel.
     * @return array<string, mixed>
     */
    protected static function outboardAt(string $field, int $index, string $fuel): array {
        return [
            "$field.$index" => 'dy:outboard',
            "$field.$index.horsepower" => 300,
            "$field.$index.propellers" => 2,
            "$field.$index.fuel" => $fuel,
        ];
    }

    /** The value of $column in $table's row for entity $id, as stored. */
    protected function stored(string $table, string $column, string $id): mixed {
        return $this->connection->fetchOne(sprintf('SELECT %s FROM %s WHERE %s = ?', $column, $table, ColumnNames::ID), [$id]);
    }

    /** The concrete identifier stored for entity $id. */
    protected function typeOf(string $id): string {
        return $this->connection->fetchOne(sprintf('SELECT concrete_identifier FROM %s WHERE id = ?', DatabaseSchema::ENTITIES), [$id]);
    }

    /** @return list<string> The ids owned by $ownerId at $field, in their order. */
    protected function ownedIds(string $ownerId, string $field): array {
        return $this->connection->fetchFirstColumn(sprintf('SELECT id FROM %s WHERE owner = ? AND owner_field = ? ORDER BY position', DatabaseSchema::ENTITIES), [$ownerId, $field]);
    }
}
