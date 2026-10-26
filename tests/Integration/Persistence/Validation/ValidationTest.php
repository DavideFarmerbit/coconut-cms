<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Validation;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

final class ValidationTest extends TestCase
{
    private Connection $connection;
    private Persistence $persistence;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture file holds several classes: load it by its own.
        class_exists(ValidationFixtures::class);

        $this->persistence = Persistence::boot($this->connection, [new ValidationModule()]);
        $this->persistence->migrate();
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** A vehicle every rule accepts. */
    private static function valid(): ValVehicle {
        $vehicle = new ValVehicle();
        $vehicle->name = 'Family';

        return $vehicle;
    }

    /** @return array<string, list<string>> What saving $entity failed with. */
    private function errorsSaving(object $entity): array {
        try {
            $this->persistence->save($entity);
        } catch (ValidationFailed $failed) {
            return $failed->errors;
        }

        self::fail('The save should have failed.');
    }

    private static function product(string $code, ?string $slug = null): ValProduct {
        $product = new ValProduct();
        $product->code = $code;
        $product->slug = $slug;

        return $product;
    }

    private static function part(?string $serial): ValPart {
        $part = new ValPart();
        $part->serial = $serial;

        return $part;
    }

    private static function label(string $text): ValLabel {
        $label = new ValLabel();
        $label->text = $text;

        return $label;
    }

    public function testAUniqueGroupTheDatabaseRefusesNamesItsField(): void {
        $this->persistence->save(self::product('A-1'));

        self::assertSame(['code' => ['must be unique: another entity already has it']], $this->errorsSaving(self::product('A-1')));
    }

    public function testAnEntitySavedAgainNeverConflictsWithItself(): void {
        $product = self::product('A-1', 'first');
        $product->parts = [self::part('S-1')];
        $this->persistence->save($product);

        $product->parts = [self::part('S-1')];
        self::assertNotNull($this->persistence->save($product), 'A new part with the old one\'s serial, in the same save that drops it.');
    }

    public function testNullsNeverConflict(): void {
        $this->persistence->save(self::product('A-1'));
        $this->persistence->save(self::product('A-2'));

        self::assertSame(2, $this->persistence->query(ValProduct::class)->count());
    }

    public function testTwoItemsOfOneSaveConflictingNameTheSecond(): void {
        $parts = self::product('A-1');
        $parts->parts = [self::part('S-1'), self::part('S-1')];
        self::assertSame(['parts.1.serial' => ['must be unique: another entity already has it']], $this->errorsSaving($parts));

        $labels = self::product('A-2');
        $labels->labels = [self::label('new'), self::label('new')];
        self::assertSame(['labels.1.text' => ['must be unique: another entity already has it']], $this->errorsSaving($labels));
    }

    public function testAGroupScopedToItsOwnerAllowsTheSameValueElsewhere(): void {
        $first = self::product('A-1');
        $first->parts = [self::part('S-1')];
        $second = self::product('A-2');
        $second->parts = [self::part('S-1')];

        $this->persistence->save($first);
        self::assertNotNull($this->persistence->save($second));
    }

    public function testAValidEntityIsSaved(): void {
        $id = $this->persistence->save(self::valid());

        self::assertNotNull($this->persistence->load($id));
    }

    public function testAnUntouchedValueIsCheckedToo(): void {
        self::assertSame(['name' => ['is required']], $this->errorsSaving(new ValVehicle()), 'The default name is empty.');
    }

    public function testEveryErrorIsCollectedByPathAndNothingIsWritten(): void {
        $vehicle = self::valid();
        $vehicle->name = 'Much too long a name';
        $vehicle->sold = true;
        $vehicle->chassis->weight = 3000;
        $vehicle->tags = ['ok', 'far too long'];

        self::assertSame([
            '' => ['a sold vehicle needs a price'],
            'name' => ['must be at most 10 characters'],
            'chassis' => ['weight must be between 500 and 2000'],
            'tags.1' => ['must be at most 5 characters'],
        ], $this->errorsSaving($vehicle));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM entities WHERE concrete_identifier = 'val:vehicle'"));
    }

    public function testARuleOnAnOwnedSlotCanRestrictItsClass(): void {
        $vehicle = self::valid();
        $vehicle->engine = new ValEngine();

        self::assertSame(['engine' => [sprintf('must be a %s', ValTurboEngine::class)]], $this->errorsSaving($vehicle));

        $vehicle->engine = new ValTurboEngine();
        self::assertNotNull($this->persistence->save($vehicle));
    }

    public function testAnOwnedObjectIsCheckedWithItsOwnTypesRules(): void {
        $vehicle = self::valid();
        $vehicle->engine = new ValTurboEngine();
        $vehicle->engine->horsepower = 0;
        $spare = new ValEngine();
        $spare->horsepower = -5;
        $vehicle->spares = [new ValEngine(), $spare];

        self::assertSame([
            'engine.horsepower' => ['must be above zero'],
            'spares.1.horsepower' => ['must be above zero'],
        ], $this->errorsSaving($vehicle));
    }
}
