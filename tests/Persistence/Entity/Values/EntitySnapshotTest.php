<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Values;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityValues;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntitySnapshot;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsCar;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsEngine;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsFixtures;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsMaker;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsTurbo;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows\RowsWheel;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

final class EntitySnapshotTest extends TestCase
{
    private static function view(): EntityView {
        $stores = new InMemorySchemaStores();
        $stores->compiler()->compile(NativeEntityReader::readAll(RowsFixtures::CLASSES));
        $identities = new IdentityMap();
        $maker = new RowsMaker();
        $identities->remember($maker, 'maker-id');

        $car = new RowsCar();
        $car->name = 'Family';
        $car->chassis->weight = 1200;
        $car->engine->horsepower = 450;
        $car->engine->turbo = new RowsTurbo();
        $car->spares = [null, new RowsEngine()];
        $car->wheels = [new RowsWheel(), new RowsWheel()];
        $car->wheels[1]->size = 19;
        $car->tags = ['red', 'fast'];
        $car->maker = $maker;

        $values = (new NativeEntityValues($stores->registry, $stores->values, $identities))->of($car);

        return EntitySnapshot::of(new SchemaLookup($stores->registry), $values->identifier, $values->dense);
    }

    public function testValuesComeAsAPropertyHoldsThem(): void {
        $view = self::view();

        self::assertSame('default:rows_car', $view->type());
        self::assertTrue($view->isA('default:rows_vehicle'));
        self::assertFalse($view->isA('default:rows_engine'));
        self::assertSame('Family', $view->get('name'));
        self::assertSame(['red', 'fast'], $view->get('tags'));
        self::assertSame('maker-id', $view->get('maker'), 'A Reference reads as its target\'s id.');
    }

    public function testAnEmbedOrAnOwnedObjectIsAnotherView(): void {
        $view = self::view();

        $chassis = $view->get('chassis');
        self::assertInstanceOf(EntityView::class, $chassis);
        self::assertSame(1200, $chassis->get('weight'));
        self::assertSame(1200, $view->get('chassis:weight'));

        self::assertSame(450, $view->get('engine')->get('horsepower'));
        self::assertSame(450, $view->get('engine.horsepower'));
        self::assertSame(2, $view->get('engine.turbo.boost'));
    }

    public function testACollectionsItemsAreReachedByIndex(): void {
        $view = self::view();

        $spares = $view->get('spares');
        self::assertNull($spares[0], 'An empty slot.');
        self::assertSame(300, $spares[1]->get('horsepower'));
        self::assertSame(19, $view->get('wheels.1.size'), 'An Embed item\'s fields are traversed.');
        self::assertSame(19, $view->get('wheels.1')->get('size'));
        self::assertSame('fast', $view->get('tags.1'));
    }

    public function testHasSaysWhetherAPathLeadsToAValue(): void {
        $view = self::view();

        self::assertTrue($view->has('engine.turbo.boost'));
        self::assertFalse($view->has('colour'));
        self::assertFalse($view->has('tags.5'), 'Past the last item.');
        self::assertFalse($view->has('spares.0.horsepower'), 'An empty slot holds nothing.');
        self::assertFalse($view->has('chassis.weight'), 'An Embed is entered with ":".');
    }

    public function testGetThrowsForAPathLeadingNowhere(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"default:rows_car" has no value at "colour".');

        self::view()->get('colour');
    }
}
