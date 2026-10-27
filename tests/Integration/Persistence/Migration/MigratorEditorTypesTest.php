<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\Migrator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** A native migration carries editor-created types along: their schemas, their own defaults, their content. */
final class MigratorEditorTypesTest extends TestCase
{
    private Connection $connection;
    private string $boatId;
    private string $vehicleId;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(MigratorEditorTypes::class);

        $this->migrate(MigratorEditorTypes::V1, MigrationSteps::none());
        [$schemas, $values, $registry] = $this->stores();
        MigratorEditorTypes::create($this->connection, $schemas, $values, $registry, ['ed:boat' => MigratorEditorTypes::boat()]);

        // Its hull and engine set; its wheels, price and color its defaults.
        $this->boatId = MigratorEditorTypes::save($this->connection, $values, $registry, 'ed:boat', [
            'hull' => 'steel',
            'engine' => 'ed:engine',
            'engine.horsepower' => 300,
        ]);
        $vehicle = new EdVehicle();
        $vehicle->name = 'Van';
        $this->vehicleId = (new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap()))->save($vehicle);
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** @return array{SchemaStore, SchemaFieldValueStore, SchemaRegistry} What a new request starts with. */
    private function stores(): array {
        $codec = new EntitySchemaCodec(new FieldValidatorRegistry(), new PrototypeValidatorRegistry(), new ValueObjectRegistry(), new EnumRegistry());
        $schemas = new SchemaStore($this->connection, $codec);

        return [$schemas, new SchemaFieldValueStore($this->connection), new SchemaRegistry($schemas)];
    }

    /** @param list<class-string> $classes */
    private function migrate(array $classes, MigrationSteps $steps): MigrationResult {
        [$schemas, $values, $registry] = $this->stores();

        return (new Migrator($this->connection, $registry, $schemas, $values))->migrate(NativeEntityReader::readAll($classes), $steps);
    }

    /** The boat's column $column, as stored. */
    private function boat(string $column): mixed {
        return $this->connection->fetchOne(sprintf('SELECT %s FROM ed_vehicle WHERE _id = ?', $column), [$this->boatId]);
    }

    /** @return array<string, mixed> What the boat type sets itself, as stored. */
    private function boatDefaults(): array {
        return $this->stores()[1]->load('ed:boat')->toArray();
    }

    public function testAnEditorTypeFollowsAChangedDefaultOfItsNativeParent(): void {
        $result = $this->migrate([...array_diff(MigratorEditorTypes::V1, [EdVehicle::class]), EdBlackVehicle::class], MigrationSteps::none());

        self::assertSame(2, $result->moved, 'The van and the boat.');
        self::assertSame('black', $this->boat('color'), 'Inherited, never set: it follows the parent.');
        self::assertSame(0, (int) $this->boat('wheels'), 'Set by the boat type itself: it stays.');
        self::assertArrayNotHasKey('color', $this->boatDefaults());

        [, $values, $registry] = $this->stores();
        self::assertSame('ed:boat', (new EntityReader($this->connection, $registry, $values))->read($this->boatId)->identifier, 'Compiled again: its fingerprints match its new parent.');
    }

    public function testARenamedInheritedFieldRenamesWhatTheEditorTypeSetsAndHolds(): void {
        $this->migrate(
            [...array_diff(MigratorEditorTypes::V1, [EdVehicle::class]), EdVehicleWithCost::class],
            MigrationSteps::none()->add('cost', [new RenameField('ed:vehicle', 'price', 'cost')]),
        );

        self::assertSame(5, $this->boatDefaults()['cost'] ?? null, 'Its own default follows the rename.');
        self::assertArrayNotHasKey('price', $this->boatDefaults());
        self::assertSame(5, (int) $this->boat('cost'), 'Its content follows it.');
    }

    public function testARenamedTypeTheEditorTypeOwnsRetargetsItsField(): void {
        $this->migrate(
            [...array_diff(MigratorEditorTypes::V1, [EdEngine::class]), EdMotor::class],
            MigrationSteps::none()->add('motor', [new RenameType('ed:engine', 'ed:motor')]),
        );

        [$schemas] = $this->stores();
        $boat = $schemas->load('ed:boat');
        self::assertSame(SchemaSource::Editor, $boat->source);
        self::assertSame('ed:motor', $boat->schema->fields->get(FieldName::of('engine'))->target());
        $engine = $this->connection->fetchAssociative("SELECT id, concrete_identifier FROM entities WHERE owner = ? AND owner_field = 'engine'", [$this->boatId]);
        self::assertSame('ed:motor', $engine['concrete_identifier']);
        self::assertSame(300, (int) $this->connection->fetchOne('SELECT horsepower FROM ed_motor WHERE _id = ?', [$engine['id']]));
    }

    public function testAnEditorTypeNothingChangedForStaysWhereItIs(): void {
        $result = $this->migrate([...array_diff(MigratorEditorTypes::V1, [EdEngine::class]), EdMotor::class], MigrationSteps::none()->add('motor', [new RenameType('ed:engine', 'ed:motor')]));

        self::assertSame(1, $result->moved, 'The boat owns an engine; the van holds none.');
        self::assertSame('Van', $this->connection->fetchOne('SELECT name FROM ed_vehicle WHERE _id = ?', [$this->vehicleId]));
    }
}
