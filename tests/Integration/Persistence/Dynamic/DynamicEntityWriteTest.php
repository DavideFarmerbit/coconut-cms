<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic;

use PHPUnit\Framework\Attributes\DataProvider;
use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\InvalidValue;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;

/**
 * DynamicEntity::set(): a value is checked where it's set, then goes on the
 * native view, through its setter, or into the bag.
 */
final class DynamicEntityWriteTest extends DynamicTestCase
{
    protected function setUp(): void {
        parent::setUp();
        DyMotor::$setterCalls = 0;
    }

    public function testAFieldOfAnEditorCreatedLevelGoesIntoTheBag(): void {
        $yacht = $this->session()->loadDynamic($this->store('dy:yacht', []));

        $yacht->set('cabins', 7);

        self::assertSame(7, $yacht->get('cabins'));
        self::assertSame(['cabins'], $yacht->changed());
    }

    public function testAFieldOfANativeLevelGoesOnTheView(): void {
        $yacht = $this->session()->loadDynamic($this->store('dy:yacht', []));

        $yacht->set('name', 'Seabird');

        self::assertSame('Seabird', $yacht->view()->name, 'Native code holding the view sees it.');
        self::assertSame('Seabird', $yacht->get('name'));
    }

    public function testANativeFieldGoesThroughItsSetter(): void {
        $craft = $this->session()->loadDynamic($this->craftWithOutboard());

        $craft->set('motor.horsepower', 500);

        self::assertSame(1, DyMotor::$setterCalls);
        self::assertSame(500, $craft->get('motor.horsepower'));
        self::assertSame(['motor.horsepower'], $craft->changed());
        self::assertSame(['horsepower'], $craft->get('motor')->changed(), 'Each entity knows what was set on it, from itself.');
    }

    public function testASetterRefusingAValueIsAValidationFailureAtThePathGiven(): void {
        $craft = $this->session()->loadDynamic($this->craftWithOutboard());

        try {
            $craft->set('motor.horsepower', -5);
            self::fail('The setter should refuse it.');
        } catch (ValidationFailed $refused) {
            self::assertSame(['motor.horsepower' => ['A motor can\'t have negative horsepower.']], $refused->errors);
        }
    }

    public function testAnOwnedObjectsBagFieldIsSetThroughThePath(): void {
        $craft = $this->session()->loadDynamic($this->craftWithOutboard());

        $craft->set('motor.fuel', 'hydrogen');

        self::assertSame('hydrogen', $craft->get('motor.fuel'));
    }

    public function testAnItemIsSetInItsCollection(): void {
        $craft = $this->session()->loadDynamic($this->store('dy:craft', ['spares' => 2, ...self::outboardAt('spares', 0, 'first'), ...self::outboardAt('spares', 1, 'second')]));

        $craft->set('spares.1.fuel', 'third');
        $craft->set('spares.0', null);

        self::assertSame('third', $craft->get('spares.1.fuel'));
        self::assertNull($craft->get('spares.0'));
        self::assertCount(2, $craft->view()->spares);
    }

    public function testAnOwnedObjectIsMovedAsItself(): void {
        $yacht = $this->session()->loadDynamic($this->store('dy:yacht', ['dinghy' => 'dy:outboard', 'dinghy.horsepower' => 20, 'dinghy.propellers' => 1, 'dinghy.fuel' => 'electric']));
        $dinghy = $yacht->get('dinghy');

        $yacht->set('motor', $dinghy);
        $yacht->set('dinghy', null);

        self::assertSame($dinghy->view(), $yacht->view()->motor, 'The bag\'s motor, now on the view.');
        self::assertSame('electric', $yacht->get('motor.fuel'));
        self::assertNull($yacht->get('dinghy'));
    }

    public function testAReferenceIsSetToItsTargetInEitherForm(): void {
        $session = $this->session();
        $yachtId = $this->store('dy:yacht', []);
        $craft = $session->loadDynamic($this->store('dy:craft', []));

        $craft->set('tow', $session->loadDynamic($yachtId));
        self::assertSame($yachtId, $craft->get('tow'));
        self::assertSame($session->load($yachtId), $craft->view()->tow);

        $craft->set('tow', $session->load($yachtId));
        self::assertSame($yachtId, $craft->get('tow'));
    }

    /** @return iterable<string, array{string, mixed, string, string}> */
    public static function wrongValues(): iterable {
        yield 'a string for an int' => ['dy:yacht', 'cabins', 'three', 'cabins'];
        yield 'null where it may not be' => ['dy:yacht', 'cabins', null, 'cabins'];
        yield 'an int for a string on the view' => ['dy:yacht', 'name', 3, 'name'];
        yield 'a scalar for an owned object' => ['dy:yacht', 'dinghy', 'dy:outboard', 'dinghy'];
        yield 'no list for a Collection' => ['dy:craft', 'spares', 'none', 'spares'];
        yield 'an unknown field' => ['dy:craft', 'cabins', 3, 'cabins'];
    }

    #[DataProvider('wrongValues')]
    public function testAWrongValueIsRefusedWhereItsSet(string $type, string $path, mixed $value, string $named): void {
        $entity = $this->session()->loadDynamic($this->store($type, []));

        try {
            $entity->set($path, $value);
            self::fail('The value should be refused.');
        } catch (InvalidValue $invalid) {
            self::assertSame($named, $invalid->path);
        }
    }

    public function testAnEntityOfAnotherTypeIsRefused(): void {
        $session = $this->session();
        $yacht = $session->loadDynamic($this->store('dy:yacht', []));

        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('dy:motor');
        $yacht->set('motor', $session->loadDynamic($this->store('dy:buoy', [])));
    }

    public function testAReferenceToAnEntityNeverSavedIsRefused(): void {
        $craft = $this->session()->loadDynamic($this->store('dy:craft', []));

        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('never saved');
        $craft->set('tow', new DyCraft());
    }

    public function testAnErrorInsideAnOwnedObjectNamesThePathGiven(): void {
        $craft = $this->session()->loadDynamic($this->craftWithOutboard());

        try {
            $craft->set('motor.fuel', 7);
            self::fail('The value should be refused.');
        } catch (InvalidValue $invalid) {
            self::assertSame('motor.fuel', $invalid->path);
        }
    }
}
