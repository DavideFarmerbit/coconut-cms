<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\ReplaceType;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\Migrator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** ReplaceType: every entity of a type becomes one of another, keeping its id; values follow field names. */
final class MigratorReplaceTest extends TestCase
{
    private Connection $connection;

    /** @var array<string, string> What setUp() saved: ids by name. */
    private array $ids;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(MigratorReplace::class);

        $this->migrate(MigratorReplace::V1, MigrationSteps::none());
        [, $values, $registry] = $this->stores();
        $writer = new NativeEntityWriter(EntityWriter::of($this->connection, $registry), $registry, $values, new IdentityMap());

        $turbo = new RepTurbo();
        $turbo->name = 'Standalone turbo';
        $turbo->power = 120;
        $sport = new RepSport();
        $sport->name = 'Sport';
        $sport->power = 200;
        $sport->stripes = 3;
        $car = new RepCar();
        $car->engine = new RepTurbo();
        $car->engine->name = 'Owned turbo';
        $car->engine->power = 90;

        $this->ids['turbo'] = $writer->save($turbo);
        $this->ids['sport'] = $writer->save($sport);
        $car->favorite = $turbo;
        $this->ids['car'] = $writer->save($car);
        $this->ids['ownedTurbo'] = $this->connection->fetchOne("SELECT id FROM entities WHERE owner = ? AND owner_field = 'engine'", [$this->ids['car']]);
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

    private function replace(ReplaceType $replace): MigrationResult {
        return $this->migrate(MigratorReplace::V2, MigrationSteps::none()->add('electric', [$replace]));
    }

    /** Entity $id's concrete type. */
    private function typeOf(string $id): string|false {
        return $this->connection->fetchOne('SELECT concrete_identifier FROM entities WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|false Entity $id's row at the electric level. */
    private function electricRow(string $id): array|false {
        return $this->connection->fetchAssociative('SELECT * FROM rep_electric WHERE _id = ?', [$id]);
    }

    public function testEveryEntityOfTheTypeBecomesOneOfTheReplacementKeepingItsId(): void {
        $this->replace(new ReplaceType('rep:turbo', 'rep:electric', initializers: ['battery' => new RepFullBattery()], converters: ['power' => new RepPowerToKilowatts()]));

        self::assertSame('rep:electric', $this->typeOf($this->ids['turbo']));
        self::assertSame('rep:electric', $this->typeOf($this->ids['ownedTurbo']), 'Owned or standalone.');
        self::assertSame('Standalone turbo', $this->connection->fetchOne('SELECT name FROM rep_engine WHERE _id = ?', [$this->ids['turbo']]), 'A field both have, the same, keeps its value.');

        $electric = $this->electricRow($this->ids['turbo']);
        self::assertSame('120 kW', $electric['power'] ?? null, 'A field both have differently goes through its converter.');
        self::assertSame(100, (int) ($electric['battery'] ?? 0), 'A field only the replacement has gets its initializer.');
        self::assertSame('90 kW', $this->electricRow($this->ids['ownedTurbo'])['power'] ?? null);
        self::assertSame($this->ids['turbo'], $this->connection->fetchOne('SELECT favorite FROM rep_car WHERE _id = ?', [$this->ids['car']]), 'A Reference to it still holds it.');
        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['rep_turbo']), 'Its own level is gone, boost with it.');
    }

    public function testASubtypeIsReparentedOntoTheReplacement(): void {
        $this->replace(new ReplaceType('rep:turbo', 'rep:electric', converters: ['power' => new RepPowerToKilowatts()]));

        self::assertSame('rep:sport', $this->typeOf($this->ids['sport']), 'It keeps its own type.');
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT stripes FROM rep_sport WHERE _id = ?', [$this->ids['sport']]));
        self::assertSame('200 kW', $this->electricRow($this->ids['sport'])['power'] ?? null, 'What it inherited is converted too.');
        self::assertSame(50, (int) ($this->electricRow($this->ids['sport'])['battery'] ?? 0), 'Without an initializer, a field gained follows its default.');
    }

    public function testAResetLeavesTheReplacementsDefault(): void {
        $this->replace(new ReplaceType('rep:turbo', 'rep:electric', resets: ['power']));

        self::assertSame('0 kW', $this->electricRow($this->ids['turbo'])['power'] ?? null);
    }

    public function testAFieldBothHaveDifferentlyLeftUndeclaredFailsBeforeAnythingChanges(): void {
        try {
            $this->replace(new ReplaceType('rep:turbo', 'rep:electric'));
            self::fail('An undeclared conflict should fail.');
        } catch (InvalidFixup $invalid) {
            self::assertStringContainsString('"power" differ between "rep:turbo" and "rep:electric": give each a converter, or reset it.', $invalid->getMessage());
        }

        self::assertSame('rep:turbo', $this->typeOf($this->ids['turbo']));
    }

    public function testAMisnamedInitializerFails(): void {
        $this->expectException(InvalidFixup::class);
        $this->expectExceptionMessage('"batery" isn\'t a field only "rep:electric" has');

        $this->replace(new ReplaceType('rep:turbo', 'rep:electric', initializers: ['batery' => new RepFullBattery()], resets: ['power']));
    }
}
