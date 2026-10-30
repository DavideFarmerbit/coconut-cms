<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic;

use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;

/**
 * Saving a DynamicEntity writes it as its real type: its view's fields and
 * its bag's, the owned objects in either, afterEdit() first.
 */
final class DynamicEntitySaveTest extends DynamicTestCase
{
    protected function setUp(): void {
        parent::setUp();
        DyEdits::$calls = [];
    }

    public function testItSavesTheViewsFieldsAndTheBags(): void {
        $id = $this->store('dy:yacht', []);
        $session = $this->session();
        $yacht = $session->loadDynamic($id);
        $yacht->set('name', 'Seabird');
        $yacht->set('cabins', 6);

        self::assertSame($id, $session->save($yacht));

        $loaded = $this->session()->loadDynamic($id);
        self::assertSame('Seabird', $loaded->get('name'));
        self::assertSame(6, $loaded->get('cabins'));
        self::assertSame('dy:yacht', $this->typeOf($id));
    }

    public function testAFullyEditorCreatedEntitySavesItsBag(): void {
        $id = $this->store('dy:buoy', []);
        $session = $this->session();
        $buoy = $session->loadDynamic($id);
        $buoy->set('color', 'green');

        $session->save($buoy);

        self::assertSame('green', $this->session()->loadDynamic($id)->get('color'));
    }

    public function testAnOwnedViewsBagIsSavedWithIt(): void {
        $id = $this->craftWithOutboard();
        [$motorId] = $this->ownedIds($id, 'motor');
        $session = $this->session();
        $craft = $session->loadDynamic($id);
        $craft->set('motor.fuel', 'hydrogen');

        $session->save($craft);

        self::assertSame([$motorId], $this->ownedIds($id, 'motor'), 'The same motor.');
        self::assertSame('hydrogen', $this->stored('dy_outboard', 'fuel', $motorId));
    }

    public function testAnOwnedViewNobodyStoodForKeepsWhatItsClassCannotSee(): void {
        $id = $this->store('dy:yacht', ['motor' => 'dy:outboard', 'motor.horsepower' => 300, 'motor.propellers' => 2, 'motor.fuel' => 'diesel']);
        [$motorId] = $this->ownedIds($id, 'motor');
        $session = $this->session();
        $yacht = $session->loadDynamic($id);
        $yacht->set('cabins', 4);

        $session->save($yacht);

        self::assertSame('diesel', $this->stored('dy_outboard', 'fuel', $motorId), 'Never reached: kept as stored.');
    }

    public function testAnOwnedObjectOnlyTheBagHoldsIsSavedThroughItsView(): void {
        $id = $this->store('dy:yacht', ['dinghy' => 'dy:outboard', 'dinghy.horsepower' => 20, 'dinghy.propellers' => 1, 'dinghy.fuel' => 'electric']);
        [$dinghyId] = $this->ownedIds($id, 'dinghy');
        $session = $this->session();
        $yacht = $session->loadDynamic($id);
        $yacht->set('dinghy.horsepower', 25);
        $yacht->set('dinghy.fuel', 'solar');

        $session->save($yacht);

        self::assertSame([$dinghyId], $this->ownedIds($id, 'dinghy'));
        self::assertSame(25, (int) $this->stored(NativeIdentifiers::tableOf('dy:motor'), 'horsepower', $dinghyId));
        self::assertSame('solar', $this->stored('dy_outboard', 'fuel', $dinghyId));
    }

    public function testAnOwnedObjectMovedFromTheBagToTheViewKeepsItsId(): void {
        $id = $this->store('dy:yacht', ['dinghy' => 'dy:outboard', 'dinghy.horsepower' => 20, 'dinghy.propellers' => 1, 'dinghy.fuel' => 'electric']);
        [$dinghyId] = $this->ownedIds($id, 'dinghy');
        $session = $this->session();
        $yacht = $session->loadDynamic($id);
        $yacht->set('motor', $yacht->get('dinghy'));
        $yacht->set('dinghy', null);

        $session->save($yacht);

        self::assertSame([$dinghyId], $this->ownedIds($id, 'motor'));
        self::assertSame([], $this->ownedIds($id, 'dinghy'));
        self::assertSame('electric', $this->stored('dy_outboard', 'fuel', $dinghyId));
    }

    public function testValuesAreComparedWithTheRealTypesDefaults(): void {
        $id = $this->craftWithOutboard();
        [$motorId] = $this->ownedIds($id, 'motor');
        $session = $this->session();
        $craft = $session->loadDynamic($id);
        $craft->set('motor.fuel', 'petrol');

        $session->save($craft);

        $state = ColumnNames::stateTable('dy_outboard');
        self::assertFalse((bool) $this->stored($state, 'fuel', $motorId), 'Petrol is the outboard\'s default: untouched.');
        self::assertFalse((bool) $this->stored(ColumnNames::stateTable(NativeIdentifiers::tableOf('dy:motor')), 'horsepower', $motorId));
    }

    public function testAfterEditRunsOnEveryViewChangedTheDeepestFirst(): void {
        $id = $this->craftWithOutboard();
        $session = $this->session();
        $craft = $session->loadDynamic($id);
        $craft->set('motor.horsepower', 500);
        $craft->set('name', 'Racer');

        $session->save($craft);

        self::assertSame([
            [DyMotor::class, ['horsepower']],
            [DyCraft::class, ['motor.horsepower', 'name']],
        ], DyEdits::$calls);
        self::assertSame([], $craft->changed(), 'Saved: nothing is left to tell.');
        self::assertSame([], $craft->get('motor')->changed());
    }

    public function testAfterEditRefusingTheEditWritesNothing(): void {
        $id = $this->craftWithOutboard();
        $session = $this->session();
        $craft = $session->loadDynamic($id);
        $craft->set('motor.horsepower', 13);

        try {
            $session->save($craft);
            self::fail('afterEdit() should refuse it.');
        } catch (ValidationFailed $refused) {
            self::assertSame(['motor' => ['13 horsepower brings bad luck.']], $refused->errors);
        }

        self::assertSame(300, $this->session()->loadDynamic($id)->get('motor.horsepower'));
    }

    public function testANativeEntitySavesThroughItsDynamicEntityToo(): void {
        $id = $this->store('dy:craft', ['name' => 'Dinghy']);
        $session = $this->session();
        $craft = $session->loadDynamic($id);
        $craft->set('name', 'Skiff');

        $session->save($craft);

        self::assertSame('Skiff', $this->session()->load($id)->name);
    }

    public function testNativeCodeAndTheDynamicEntitySaveOneEntity(): void {
        $id = $this->store('dy:yacht', []);
        $session = $this->session();
        $native = $session->load($id);
        $dynamic = $session->loadDynamic($id);
        $native->name = 'Set natively';
        $dynamic->set('cabins', 9);

        $session->save($dynamic);

        $loaded = $this->session()->loadDynamic($id);
        self::assertSame('Set natively', $loaded->get('name'));
        self::assertSame(9, $loaded->get('cabins'));
    }
}
