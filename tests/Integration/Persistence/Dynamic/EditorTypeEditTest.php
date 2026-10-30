<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic;

use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\InvalidChange;

/**
 * Edits by path, what the API's entities.create, update and delete run, work
 * on editor-created entities as on native ones: through a DynamicEntity.
 */
final class EditorTypeEditTest extends DynamicTestCase
{
    protected function setUp(): void {
        parent::setUp();
        DyMotor::$setterCalls = 0;
        DyEdits::$calls = [];
    }

    public function testAnEditorCreatedEntityIsCreatedByPath(): void {
        $id = $this->session()->create('dy:yacht', [
            'name' => 'Seabird',
            'cabins' => 4,
            'dinghy' => ['type' => 'dy:outboard', 'values' => ['fuel' => 'solar', 'horsepower' => 40]],
        ]);

        $yacht = $this->session()->loadDynamic($id);
        self::assertSame('dy:yacht', $yacht->type());
        self::assertSame('Seabird', $yacht->get('name'));
        self::assertSame(4, $yacht->get('cabins'));
        self::assertSame('solar', $yacht->get('dinghy.fuel'));
        self::assertSame(40, $yacht->get('dinghy.horsepower'));
        self::assertSame(1, DyMotor::$setterCalls, 'The native field of a bag\'s object goes through its setter.');
    }

    public function testAnEditorCreatedEntityIsUpdatedByPath(): void {
        $id = $this->store('dy:yacht', ['dinghy' => 'dy:outboard', 'dinghy.horsepower' => 20, 'dinghy.propellers' => 1, 'dinghy.fuel' => 'electric']);
        [$dinghyId] = $this->ownedIds($id, 'dinghy');

        self::assertTrue($this->session()->update($id, [
            'cabins' => 8,
            'dinghy' => ['type' => 'dy:outboard', 'id' => $dinghyId, 'values' => ['fuel' => 'diesel']],
            'motor' => ['type' => 'dy:outboard', 'values' => ['fuel' => 'hydrogen']],
        ]));

        $yacht = $this->session()->loadDynamic($id);
        self::assertSame(8, $yacht->get('cabins'));
        self::assertSame([$dinghyId], $this->ownedIds($id, 'dinghy'), 'Kept by its id.');
        self::assertSame('diesel', $yacht->get('dinghy.fuel'));
        self::assertSame('hydrogen', $yacht->get('motor.fuel'));
        self::assertSame(300, $yacht->get('motor.horsepower'), 'A new outboard, from its own defaults.');
    }

    public function testAnEntityWithNoNativeAncestorIsEditedAndDeleted(): void {
        $session = $this->session();
        $id = $session->create('dy:buoy', ['color' => 'orange']);
        self::assertSame('orange', $this->session()->loadDynamic($id)->get('color'));

        self::assertTrue($this->session()->update($id, ['color' => 'white']));
        self::assertSame('white', $this->session()->loadDynamic($id)->get('color'));

        self::assertTrue($this->session()->deleteById($id));
        self::assertNull($this->session()->loadDynamic($id));
    }

    public function testAfterEditRunsOnAnEditByPath(): void {
        $id = $this->craftWithOutboard();

        $this->session()->update($id, ['motor.horsepower' => 450, 'name' => 'Racer']);

        self::assertSame([
            [DyMotor::class, ['horsepower']],
            [DyCraft::class, ['motor.horsepower', 'name']],
        ], DyEdits::$calls);
    }

    public function testAReferenceMayPointAtAnEditorCreatedEntityOfItsTargetType(): void {
        $yachtId = $this->store('dy:yacht', []);
        $id = $this->store('dy:craft', []);

        $this->session()->update($id, ['tow' => $yachtId]);

        self::assertSame($yachtId, $this->session()->loadDynamic($id)->get('tow'));
    }

    public function testAReferenceToAnotherTypeIsRefused(): void {
        $id = $this->store('dy:craft', []);

        $this->expectException(InvalidChange::class);
        $this->expectExceptionMessage('"tow" points at a dy:craft or a type extending it');
        $this->session()->update($id, ['tow' => $this->store('dy:buoy', [])]);
    }

    public function testAChangeItCantApplyInsideAnEditorLevelIsNamedByItsPath(): void {
        $id = $this->store('dy:yacht', []);

        try {
            $this->session()->update($id, ['dinghy' => ['type' => 'dy:outboard', 'values' => ['fuel' => 7]]]);
            self::fail('The change was applied.');
        } catch (InvalidChange $invalid) {
            self::assertSame('dinghy.fuel', $invalid->path);
        }
    }

    public function testAnOwnedObjectOfAnotherTypeIsRefused(): void {
        $id = $this->store('dy:yacht', []);

        $this->expectException(InvalidChange::class);
        $this->expectExceptionMessage('holds a dy:motor or a type extending it, not a dy:craft');
        $this->session()->update($id, ['dinghy' => ['type' => 'dy:craft']]);
    }
}
