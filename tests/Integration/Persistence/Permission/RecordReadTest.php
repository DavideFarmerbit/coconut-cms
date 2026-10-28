<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Record\EntityRecord;
use XyloIsCoding\CoconutCms\Persistence\Entity\Record\ReferenceRecord;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** PersistenceSession::read(): an entity as plain values, as its reader may see it. */
final class RecordReadTest extends TestCase
{
    private Connection $connection;
    private Persistence $persistence;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(PermissionFixtures::class);

        $this->persistence = Persistence::boot($this->connection, [new PermissionModule()]);
        $this->persistence->migrate();
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** A new session, as a request of an actor holding $roles gets. */
    private function as(string ...$roles): PersistenceSession {
        return $this->persistence->as(new PermActor(array_values($roles)));
    }

    /** A car with every field set. @return array{string, string} Its id and its maker's. */
    private function savedCar(): array {
        $system = $this->persistence->as(new SystemActor());
        $maker = new PermMaker();
        $maker->name = 'Acme';
        $system->save($maker);

        $car = new PermCar();
        $car->name = 'Family car';
        $car->price = 10;
        $car->internal = 'classified';
        $car->engine = new PermEngine();
        $car->engine->horsepower = 300;
        $car->chassis->serial = 'CH-1';
        $label = new PermLabel();
        $label->text = 'red';
        $label->note = 'repainted';
        $car->labels = [$label];
        $car->codes = ['A', 'B'];
        $car->maker = $maker;

        return [$system->save($car), $maker->id];
    }

    public function testAnAdminReadsEveryValueInStoredForm(): void {
        [$id, $makerId] = $this->savedCar();

        $record = $this->as('admin', 'mechanic', 'engine-room')->read($id);

        self::assertSame('perm:car', $record->type);
        self::assertSame($id, $record->id);
        self::assertSame('Family car', $record->values['name']);
        self::assertSame('classified', $record->values['internal']);
        self::assertEquals(new EntityRecord('perm:chassis', null, ['weight' => 1000, 'serial' => 'CH-1']), $record->values['chassis']);
        self::assertSame('perm:engine', $record->values['engine']->type);
        self::assertNotNull($record->values['engine']->id);
        self::assertSame(['horsepower' => 300], $record->values['engine']->values);
        self::assertEquals([new EntityRecord('perm:label', null, ['text' => 'red', 'note' => 'repainted'])], $record->values['labels']);
        self::assertSame(['A', 'B'], $record->values['codes']);
        self::assertEquals(new ReferenceRecord('perm:maker', $makerId), $record->values['maker']);
        self::assertNull($record->values['document']);
    }

    public function testAFieldTheReaderMayNotReadIsLeftOut(): void {
        [$id] = $this->savedCar();

        $record = $this->as()->read($id);

        self::assertSame(['name', 'price', 'chassis', 'labels', 'maker', 'document'], array_keys($record->values));
        self::assertSame(['weight' => 1000], $record->values['chassis']->values);
        self::assertSame(['text' => 'red'], $record->values['labels'][0]->values);
    }

    public function testATypeTheReaderMayNotReadIsRefused(): void {
        $maker = new PermSecretMaker();
        $maker->name = 'Hidden';
        $id = $this->persistence->as(new SystemActor())->save($maker);

        self::assertSame('Hidden', $this->as('admin')->read($id)->values['name']);

        $this->expectException(PermissionDenied::class);
        $this->as()->read($id);
    }

    public function testAnOwnedOrMissingEntityReadsAsNull(): void {
        [$id] = $this->savedCar();
        $engineId = $this->as('admin', 'engine-room')->read($id)->values['engine']->id;

        self::assertNull($this->as('admin', 'engine-room')->read($engineId));
        self::assertNull($this->as()->read('no-such-id'));
    }
}
