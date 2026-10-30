<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;

/** newDynamic() makes a new entity of any type: a `new Car()`, from its real type's defaults. */
final class NewDynamicTest extends DynamicTestCase
{
    public function testANewEditorCreatedEntityStartsFromItsTypesDefaults(): void {
        $yacht = $this->session()->newDynamic('dy:yacht');

        self::assertNull($yacht->id(), 'No id until its first save.');
        self::assertSame('dy:yacht', $yacht->type());
        self::assertSame('Yacht', $yacht->get('name'));
        self::assertSame(3, $yacht->get('cabins'));
        self::assertInstanceOf(DyCraft::class, $yacht->view());
        self::assertSame('Yacht', $yacht->view()->name, 'The yacht\'s default for a native field is in the view.');
    }

    public function testAnOverriddenNativeDefaultIsInTheView(): void {
        $outboard = $this->session()->newDynamic('dy:outboard');

        self::assertInstanceOf(DyBoatMotor::class, $outboard->view());
        self::assertSame(300, $outboard->view()->horsepower, 'The outboard\'s 300, not the motor\'s 100.');
        self::assertSame('petrol', $outboard->get('fuel'));
    }

    public function testItsFirstSaveGivesItAnIdAndMakesItTheOneObjectForIt(): void {
        $session = $this->session();
        $yacht = $session->newDynamic('dy:yacht');
        $yacht->set('cabins', 5);

        $id = $session->save($yacht);

        self::assertSame($id, $yacht->id());
        self::assertSame('dy:yacht', $this->typeOf($id));
        self::assertSame($yacht, $session->loadDynamic($id));
        self::assertSame($yacht->view(), $session->load($id));
        self::assertSame(5, $this->session()->loadDynamic($id)->get('cabins'));
    }

    public function testANewFullyEditorCreatedEntityIsFoundByItsIdOnceSaved(): void {
        $session = $this->session();
        $buoy = $session->newDynamic('dy:buoy');
        $buoy->set('color', 'blue');

        $id = $session->save($buoy);

        self::assertSame($buoy, $session->loadDynamic($id));
        self::assertSame('blue', $this->session()->loadDynamic($id)->get('color'));
    }

    public function testANewNativeEntityIsANewCar(): void {
        $session = $this->session();
        $craft = $session->newDynamic('dy:craft');
        $craft->set('name', 'Skiff');

        $id = $session->save($craft);

        self::assertSame('Skiff', $this->session()->load($id)->name);
    }

    public function testANewOwnedEntityIsSavedWithItsOwner(): void {
        $session = $this->session();
        $yacht = $session->newDynamic('dy:yacht');
        $outboard = $session->newDynamic('dy:outboard');
        $outboard->set('fuel', 'diesel');
        $yacht->set('motor', $outboard);

        $id = $session->save($yacht);

        [$motorId] = $this->ownedIds($id, 'motor');
        self::assertSame($motorId, $outboard->id());
        self::assertSame('dy:outboard', $this->typeOf($motorId));
        self::assertSame('diesel', $this->stored('dy_outboard', 'fuel', $motorId));
        self::assertSame(300, $this->session()->load($id)->motor->horsepower);
    }

    public function testDefaultsAreNotTouched(): void {
        $session = $this->session();

        $id = $session->save($session->newDynamic('dy:yacht'));

        self::assertFalse((bool) $this->stored(ColumnNames::stateTable('dy_yacht'), 'cabins', $id), 'Never set: it keeps following the yacht\'s default.');
    }

    public function testAnUnknownTypeIsRefused(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->session()->newDynamic('dy:nothing');
    }
}
