<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic;

use InvalidArgumentException;
use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\DynamicEntity;

/** loadDynamic() gives any entity whole: its native view, and a bag for the rest. */
final class DynamicEntityReadTest extends DynamicTestCase
{
    public function testAnEditorCreatedEntityReadsItsViewsFieldsAndItsBags(): void {
        $id = $this->store('dy:yacht', ['cabins' => 5]);

        $yacht = $this->session()->loadDynamic($id);

        self::assertSame('dy:yacht', $yacht->type());
        self::assertSame($id, $yacht->id());
        self::assertTrue($yacht->isA('dy:craft'));
        self::assertFalse($yacht->isA('dy:motor'));
        self::assertInstanceOf(DyCraft::class, $yacht->view());
        self::assertSame('Yacht', $yacht->get('name'), 'From the view.');
        self::assertSame(5, $yacht->get('cabins'), 'From the bag.');
    }

    public function testThereIsOneDynamicEntityPerEntityWrappingTheObjectLoadGives(): void {
        $id = $this->store('dy:yacht', []);
        $session = $this->session();

        $native = $session->load($id);
        $dynamic = $session->loadDynamic($id);

        self::assertSame($native, $dynamic->view());
        self::assertSame($dynamic, $session->loadDynamic($id));
        $native->name = 'Changed natively';
        self::assertSame('Changed natively', $dynamic->get('name'), 'One object: a change shows through both.');
    }

    public function testAFullyEditorCreatedEntityHasNoViewOnlyABag(): void {
        $id = $this->store('dy:buoy', ['color' => 'yellow']);
        $session = $this->session();

        $buoy = $session->loadDynamic($id);

        self::assertNull($buoy->view());
        self::assertSame('yellow', $buoy->get('color'));
        self::assertSame($buoy, $session->loadDynamic($id));
    }

    public function testANativeEntityHasAnEmptyBag(): void {
        $id = $this->store('dy:craft', ['name' => 'Dinghy']);

        $craft = $this->session()->loadDynamic($id);

        self::assertSame('dy:craft', $craft->type());
        self::assertSame('Dinghy', $craft->get('name'));
        self::assertNull($craft->get('motor'));
    }

    public function testAnOwnedViewComesBackAsItsOwnDynamicEntity(): void {
        $id = $this->craftWithOutboard();
        $session = $this->session();

        $craft = $session->loadDynamic($id);
        $motor = $craft->get('motor');

        self::assertInstanceOf(DynamicEntity::class, $motor);
        self::assertSame('dy:outboard', $motor->type());
        self::assertSame($session->load($id)->motor, $motor->view(), 'The very object the craft\'s view holds.');
        self::assertSame($motor, $craft->get('motor'), 'One per object.');
        self::assertSame(300, $craft->get('motor.horsepower'), 'Its view\'s field.');
        self::assertSame('diesel', $craft->get('motor.fuel'), 'Its bag\'s field.');
        self::assertSame($this->ownedIds($id, 'motor')[0], $motor->id());
    }

    public function testAnOwnedObjectOnlyTheBagHoldsHasItsOwnView(): void {
        $id = $this->store('dy:yacht', ['dinghy' => 'dy:outboard', 'dinghy.horsepower' => 20, 'dinghy.propellers' => 1, 'dinghy.fuel' => 'electric']);

        $dinghy = $this->session()->loadDynamic($id)->get('dinghy');

        self::assertSame('dy:outboard', $dinghy->type());
        self::assertSame($this->ownedIds($id, 'dinghy')[0], $dinghy->id());
        self::assertInstanceOf(DyBoatMotor::class, $dinghy->view());
        self::assertSame(20, $dinghy->view()->horsepower);
        self::assertSame('electric', $dinghy->get('fuel'));
    }

    public function testACollectionsItemsAreReachedByIndex(): void {
        $id = $this->store('dy:craft', ['spares' => 2, ...self::outboardAt('spares', 0, 'first'), ...self::outboardAt('spares', 1, 'second')]);

        $craft = $this->session()->loadDynamic($id);

        self::assertCount(2, $craft->get('spares'));
        self::assertSame('second', $craft->get('spares.1.fuel'));
        self::assertSame($craft->get('spares')[1], $craft->get('spares.1'));
    }

    public function testAReferenceReadsAsItsTargetsId(): void {
        $yachtId = $this->store('dy:yacht', []);
        $id = $this->store('dy:craft', ['tow' => $yachtId]);

        self::assertSame($yachtId, $this->session()->loadDynamic($id)->get('tow'));
    }

    public function testHasTellsWhereAValueIs(): void {
        $craft = $this->session()->loadDynamic($this->craftWithOutboard());

        self::assertTrue($craft->has('motor.fuel'));
        self::assertFalse($craft->has('motor.nothing'));
        self::assertFalse($craft->has('cabins'), 'A yacht\'s field, not a craft\'s.');
        self::assertFalse($craft->has('spares.0'));
    }

    public function testNothingAtAPathThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->session()->loadDynamic($this->store('dy:buoy', []))->get('name');
    }

    public function testAnOwnedEntityIsReachedThroughItsOwner(): void {
        $id = $this->craftWithOutboard();

        $this->expectException(LogicException::class);
        $this->session()->loadDynamic($this->ownedIds($id, 'motor')[0]);
    }

    public function testThereIsNothingForAnUnknownId(): void {
        self::assertNull($this->session()->loadDynamic('01900000-0000-7000-8000-000000000000'));
    }
}
