<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Native;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\ValueFlattener;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

enum ValuesFuel: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
}

#[Entity]
final class ValuesMaker
{
    #[Scalar(ScalarType::String)]
    public string $name = '';
}

#[Entity]
final class ValuesPiston
{
    #[Scalar(ScalarType::Int)]
    public int $diameter = 10;
}

#[Entity]
class ValuesEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 300;

    #[Embed]
    public ValuesPiston $piston;

    public function __construct() {
        $this->piston = new ValuesPiston();
    }
}

#[Entity]
final class ValuesTurboEngine extends ValuesEngine
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 2;
}

#[Entity]
final class ValuesWheel
{
    #[Scalar(ScalarType::Int)]
    public int $size = 17;
}

#[Entity]
final class ValuesCar
{
    #[Scalar(ScalarType::String)]
    public string $name = 'car';

    #[Scalar(ScalarType::Enum)]
    public ValuesFuel $fuel = ValuesFuel::Petrol;

    #[Scalar(ScalarType::DateTime)]
    public ?DateTimeImmutable $soldAt = null;

    #[OwningReference]
    public ?ValuesEngine $engine;

    #[Collection(itemKind: FieldKind::Embed, itemTarget: ValuesWheel::class)]
    public array $wheels = [];

    #[Reference]
    public ?ValuesMaker $maker = null;

    public function __construct() {
        $this->engine = new ValuesEngine();
        $this->engine->horsepower = 500;
    }
}

final class NativeEntityValuesTest extends TestCase
{
    private IdentityMap $identities;
    private NativeEntityValues $values;
    private InMemorySchemaStores $stores;

    protected function setUp(): void {
        $stores = $this->stores = new InMemorySchemaStores();
        $stores->enums->register(Identifier::of('test', 'fuel'), ValuesFuel::class);
        $stores->compiler()->compile(NativeEntityReader::readAll([ValuesMaker::class, ValuesPiston::class, ValuesEngine::class, ValuesTurboEngine::class, ValuesWheel::class, ValuesCar::class]));

        $this->identities = new IdentityMap();
        $this->values = new NativeEntityValues($stores->registry, $stores->values, $this->identities);
    }

    /** @return array<string, mixed> */
    private function touchedOf(object $entity): array {
        $touched = $this->values->of($entity)->touched->toArray();
        ksort($touched, SORT_STRING);

        return $touched;
    }

    public function testOnlyTheTouchedValuesResolveBackToEveryValue(): void {
        $maker = new ValuesMaker();
        $this->identities->remember($maker, 'maker-id');
        $car = new ValuesCar();
        $car->name = 'mine';
        $car->engine = new ValuesTurboEngine();
        $car->engine->piston->diameter = 12;
        $car->wheels = [new ValuesWheel(), new ValuesWheel()];
        $car->wheels[1]->size = 19;
        $car->maker = $maker;
        $values = $this->values->of($car);

        $lookup = new SchemaLookup($this->stores->registry);
        $resolver = new FieldValueResolver($this->stores->registry, $this->stores->values);
        $tree = $resolver->resolveTree(Subject::ofInstance('default:values_car', $values->touched));

        self::assertSame($values->dense->toArray(), (new ValueFlattener($lookup))->flatten('default:values_car', $tree)->toArray());
    }

    public function testAnUntouchedEntityTouchesNothing(): void {
        self::assertSame([], $this->touchedOf(new ValuesCar()));
    }

    public function testDenseHoldsEveryValueInItsStoredForm(): void {
        $dense = $this->values->of(new ValuesCar())->dense->toArray();

        self::assertSame('petrol', $dense['fuel']);
        self::assertSame('default:values_engine', $dense['engine']);
        self::assertSame(500, $dense['engine.horsepower']);
        self::assertSame(10, $dense['engine.piston:diameter']);
        self::assertSame(0, $dense['wheels']);
    }

    public function testOnlyWhatDiffersFromTheClassIsTouched(): void {
        $car = new ValuesCar();
        $car->name = 'mine';
        $car->fuel = ValuesFuel::Diesel;
        $car->soldAt = new DateTimeImmutable('2024-05-01 10:00:00', new DateTimeZone('Europe/Rome'));

        self::assertSame(['fuel' => 'diesel', 'name' => 'mine', 'soldAt' => '2024-05-01 08:00:00'], $this->touchedOf($car));
    }

    public function testAFieldSetBackToTheClassDefaultIsUntouchedAgain(): void {
        $car = new ValuesCar();
        $car->name = 'mine';
        $car->name = 'car';

        self::assertSame([], $this->touchedOf($car));
    }

    public function testAnOwnedObjectsFieldIsComparedAgainstWhatTheClassPinsOnIt(): void {
        $car = new ValuesCar();
        $car->engine->horsepower = 300;
        $car->engine->piston->diameter = 12;

        // 300 is ValuesEngine's own default, but ValuesCar pins 500 on its engine.
        self::assertSame(['engine.horsepower' => 300, 'engine.piston:diameter' => 12], $this->touchedOf($car));
    }

    public function testSwappingTheOwnedClassTouchesOnlyTheClassAndWhatDiffers(): void {
        $car = new ValuesCar();
        $car->engine = new ValuesTurboEngine();
        $car->engine->horsepower = 500;

        // horsepower still matches what ValuesCar pins; boost matches the turbo's own default.
        self::assertSame(['engine' => 'default:values_turbo_engine'], $this->touchedOf($car));
    }

    public function testClearingAnOwnedObjectTouchesItAsNull(): void {
        $car = new ValuesCar();
        $car->engine = null;

        self::assertSame(['engine' => null], $this->touchedOf($car));
    }

    public function testACollectionIsTouchedWholeButItsItemsOnlyWhereTheyDiffer(): void {
        $car = new ValuesCar();
        $car->wheels = [new ValuesWheel(), new ValuesWheel()];
        $car->wheels[1]->size = 19;

        self::assertSame(['wheels' => 2, 'wheels.1.size' => 19], $this->touchedOf($car));
    }

    public function testAReferenceIsTouchedAsItsTargetsId(): void {
        $maker = new ValuesMaker();
        $this->identities->remember($maker, 'maker-id');
        $car = new ValuesCar();
        $car->maker = $maker;

        self::assertSame(['maker' => 'maker-id'], $this->touchedOf($car));
    }

    public function testAReferenceToAnEntityNeverSavedThrows(): void {
        $car = new ValuesCar();
        $car->maker = new ValuesMaker();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('save it first');

        $this->values->of($car);
    }
}
