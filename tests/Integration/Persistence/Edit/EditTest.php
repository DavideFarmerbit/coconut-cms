<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Edit;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\InvalidChange;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceSession;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermActor;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission\PermissionFixtures;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** Edits by path: create(), update() and deleteById() on a session, through setters and afterEdit(). */
final class EditTest extends TestCase
{
    private Connection $connection;
    private Persistence $persistence;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        // The fixture files hold several classes: load them by their own.
        class_exists(EditFixtures::class);
        class_exists(PermissionFixtures::class);

        $this->persistence = Persistence::boot($this->connection, [new EditModule()]);
        $this->persistence->migrate();
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    private function system(): PersistenceSession {
        return $this->persistence->as(new SystemActor());
    }

    private function as(string ...$roles): PersistenceSession {
        return $this->persistence->as(new PermActor(array_values($roles)));
    }

    /** A maker, saved. @return string Its id. */
    private function maker(string $name): string {
        $maker = new EditMaker();
        $maker->name = $name;

        return $this->system()->save($maker);
    }

    public function testCreateAppliesEveryKindOfValueInStoredForm(): void {
        $makerId = $this->maker('Acme');

        $id = $this->system()->create('edit:car', [
            'name' => 'Family Car',
            'fuel' => 'diesel',
            'builtOn' => '2024-05-01',
            'chassis:weight' => 1200,
            'engine' => ['type' => 'edit:turbo_engine', 'values' => ['horsepower' => 300]],
            'wheels' => [['values' => ['size' => 18]], ['values' => []]],
            'spares' => [['type' => 'edit:engine'], null],
            'tags' => ['red', 'fast'],
            'maker' => $makerId,
        ]);

        $car = $this->system()->load($id);
        self::assertSame('Family Car', $car->name);
        // Set by the private setter.
        self::assertSame('family-car', $car->slug);
        self::assertSame(EditFuel::Diesel, $car->fuel);
        self::assertSame('2024-05-01', $car->builtOn->format('Y-m-d'));
        self::assertSame(1200, $car->chassis->weight);
        self::assertInstanceOf(EditTurboEngine::class, $car->engine);
        self::assertSame(300, $car->engine->horsepower);
        // TurboEngine's override ran, on a new object too.
        self::assertSame(3, $car->engine->boost);
        self::assertSame([18, 17], array_map(static fn (EditWheel $wheel): int => $wheel->size, $car->wheels));
        self::assertInstanceOf(EditEngine::class, $car->spares[0]);
        self::assertNull($car->spares[1]);
        self::assertSame(['red', 'fast'], $car->tags);
        self::assertSame('Acme', $car->maker->name);
        self::assertSame('name,fuel,builtOn,chassis:weight,engine,wheels,spares,tags,maker', $car->lastEdit);
    }

    public function testASubclassOverridingASetterHasItsOwnRunWhateverPathReachedIt(): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car', 'engine' => ['type' => 'edit:turbo_engine']]);

        self::assertTrue($this->system()->update($id, ['engine.horsepower' => 500]));

        $car = $this->system()->load($id);
        self::assertSame(500, $car->engine->horsepower);
        self::assertSame(5, $car->engine->boost);
        // Each changed object is told its own paths, its owner every path.
        self::assertSame('horsepower', $car->engine->edited);
        self::assertSame('engine.horsepower', $car->lastEdit);
    }

    public function testAPrivateSetterRunsAsItsClassWroteIt(): void {
        $id = $this->system()->create('edit:sports_car', ['name' => 'Fast One']);

        $car = $this->system()->load($id);
        self::assertSame('Fast One', $car->name);
        self::assertSame('fast-one', $car->slug);
    }

    public function testAfterEditSeesEveryChangeAndMayChangeOtherFields(): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car', 'region' => 'Lazio', 'country' => 'Italy']);
        self::assertSame('', $this->system()->load($id)->region);

        $this->system()->update($id, ['region' => 'Lombardy']);
        self::assertSame('Lombardy', $this->system()->load($id)->region);
    }

    public function testAnOwnedItemSentWithItsIdIsKept(): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car', 'spares' => [['type' => 'edit:engine', 'values' => ['horsepower' => 150]]]]);
        $spareId = $this->system()->load($id)->spares[0]->id;

        $this->system()->update($id, ['spares' => [['type' => 'edit:engine'], ['type' => 'edit:engine', 'id' => $spareId, 'values' => ['horsepower' => 160]]]]);

        $spares = $this->system()->load($id)->spares;
        self::assertNotSame($spareId, $spares[0]->id);
        self::assertSame(100, $spares[0]->horsepower);
        self::assertSame($spareId, $spares[1]->id);
        self::assertSame(160, $spares[1]->horsepower);
    }

    public function testAChangeInsideACollectionItemReachesIt(): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car', 'wheels' => [['values' => []], ['values' => []]], 'spares' => [['type' => 'edit:turbo_engine']]]);

        $this->system()->update($id, ['wheels.1.size' => 21, 'spares.0.horsepower' => 400, 'tags' => ['a'], 'tags.0' => 'b']);

        $car = $this->system()->load($id);
        self::assertSame([17, 21], array_map(static fn (EditWheel $wheel): int => $wheel->size, $car->wheels));
        self::assertSame(4, $car->spares[0]->boost);
        self::assertSame(['b'], $car->tags);
    }

    /** @return array<string, array{array<string, mixed>, array<string, list<string>>}> */
    public static function refusals(): array {
        return [
            'a setter' => [['name' => ''], ['name' => ['A car needs a name.']]],
            'an owned object\'s setter' => [['engine' => ['type' => 'edit:engine'], 'engine.horsepower' => -1], ['engine.horsepower' => ['Horsepower can\'t be negative.']]],
            'afterEdit()' => [['name' => 'Forbidden'], ['' => ['That name is taken.']]],
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, list<string>> $errors
     */
    #[DataProvider('refusals')]
    public function testARefusalFailsTheEditAtItsPathAndSavesNothing(array $changes, array $errors): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car']);

        try {
            $this->system()->update($id, $changes);
            self::fail('The edit was saved.');
        } catch (ValidationFailed $failed) {
            self::assertSame($errors, $failed->errors);
        }

        self::assertSame('Car', $this->system()->load($id)->name);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidChanges(): array {
        return [
            'an unknown field' => [['colour' => 'red'], 'colour', '"edit:car" has no field "colour".'],
            'a value of the wrong type' => [['fuel' => 'coal'], 'fuel', '"fuel" must hold'],
            'a step through a value' => [['name.length' => 1], 'name.length', '"name" holds a value'],
            'an Embed joined by "."' => [['chassis.weight' => 1], 'chassis.weight', 'its fields follow ":"'],
            'an empty owned slot' => [['engine.horsepower' => 1], 'engine.horsepower', '"engine" is empty'],
            'a missing item' => [['wheels.3.size' => 1], 'wheels.3.size', '"wheels" has no item 3'],
            'an owned object of another type' => [['engine' => ['type' => 'edit:wheel']], 'engine', 'holds a edit:engine or a type extending it, not a edit:wheel'],
            'an owned object of no type' => [['engine' => ['values' => []]], 'engine', 'it\'s set to {"type": ...}'],
            'an owned id it doesn\'t hold' => [['engine' => ['type' => 'edit:engine', 'id' => 'nope']], 'engine', 'holds no owned object nope to keep'],
            'a Reference to nothing' => [['maker' => 'nope'], 'maker', 'there is no such entity'],
            'a Collection set to a value' => [['tags' => 'red'], 'tags', 'it\'s set to a list'],
            'a field it may not read' => [['secret' => 'x'], 'secret', '"edit:car" has no field "secret".'],
        ];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidChanges')]
    public function testAChangeItCantApplyIsNamedByItsPath(array $changes, string $path, string $message): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car']);

        try {
            $this->as()->update($id, $changes);
            self::fail('The change was applied.');
        } catch (InvalidChange $invalid) {
            self::assertSame($path, $invalid->path);
            self::assertStringContainsString($message, $invalid->getMessage());
        }
    }

    public function testAReferenceMustPointAtItsTargetType(): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car']);
        $otherCar = $this->system()->create('edit:car', ['name' => 'Other']);

        $this->expectException(InvalidChange::class);
        $this->expectExceptionMessage('"maker" points at a edit:maker or a type extending it');

        $this->system()->update($id, ['maker' => $otherCar]);
    }

    public function testPermissionsApplyAsForAnySave(): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car']);

        $this->expectException(PermissionDenied::class);
        $this->expectExceptionMessage('"price"');

        $this->as()->update($id, ['price' => 5]);
    }

    public function testAnOwnedOrMissingEntityIsNeitherUpdatedNorDeleted(): void {
        $id = $this->system()->create('edit:car', ['name' => 'Car', 'engine' => ['type' => 'edit:engine']]);
        $engineId = $this->system()->load($id)->engine->id;

        self::assertFalse($this->system()->update($engineId, ['horsepower' => 1]));
        self::assertFalse($this->system()->update('nope', []));
        self::assertFalse($this->system()->deleteById($engineId));

        self::assertTrue($this->system()->deleteById($id));
        self::assertNull($this->system()->load($id));
    }

    /** @return array<string, array{class-string, string}> */
    public static function badSetters(): array {
        return [
            'a missing method' => [EditMissingSetter::class, 'has no such method'],
            'a parameter of another type' => [EditWrongSetter::class, 'doesn\'t accept every int'],
            'two required parameters' => [EditGreedySetter::class, 'nothing else required'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('badSetters')]
    public function testASetterAnEditCantCallFailsWhenItsClassIsRead(string $class, string $message): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        NativeEntityReader::read($class);
    }
}
