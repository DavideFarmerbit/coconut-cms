<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Native;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeObjectBuilder;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeValueReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

enum BuilderFuel: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
}

final class BuilderColor implements StorableValue
{
    public function __construct(
        public readonly string $hex,
    ) {
    }

    public function toStorage(): string {
        return $this->hex;
    }

    public static function indexable(): bool {
        return true;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

/** A non-field object every car must hold a fresh copy of. */
final class BuilderLog
{
    /** @var list<string> */
    public array $lines = [];
}

#[Entity]
final class BuilderMaker
{
    #[Scalar(ScalarType::String)]
    public string $name = '';
}

#[Entity]
final class BuilderPiston
{
    #[Scalar(ScalarType::Int)]
    public int $diameter = 10;
}

#[Entity]
class BuilderEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 300;

    #[Embed]
    public BuilderPiston $piston;

    public function __construct() {
        $this->piston = new BuilderPiston();
    }
}

#[Entity]
final class BuilderTurboEngine extends BuilderEngine
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 2;
}

#[Entity]
final class BuilderCar
{
    public static int $constructed = 0;

    #[Scalar(ScalarType::String)]
    public readonly string $vin;

    #[Scalar(ScalarType::Enum)]
    public BuilderFuel $fuel = BuilderFuel::Petrol;

    #[Scalar(ScalarType::DateTime)]
    public ?DateTimeImmutable $soldAt = null;

    #[ValueObject]
    public BuilderColor $color;

    #[OwningReference]
    public ?BuilderEngine $engine;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: BuilderEngine::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    #[Collection(itemKind: FieldKind::Embed, itemTarget: BuilderPiston::class)]
    public array $pistons = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::Enum, itemEnumClass: BuilderFuel::class, itemNullability: Nullability::NotNullable)]
    public array $fuels = [];

    #[Reference]
    public ?BuilderMaker $maker = null;

    public BuilderLog $log;

    public function __construct(string $vin = 'default-vin') {
        self::$constructed++;
        $this->vin = $vin;
        $this->color = new BuilderColor('#000000');
        $this->engine = new BuilderEngine();
        $this->log = new BuilderLog();
    }

    public function __clone() {
        $this->log = clone $this->log;
    }
}

final class NativeObjectBuilderTest extends TestCase
{
    private BuilderMaker $maker;
    private NativeValueReader $reader;
    private NativeObjectBuilder $builder;

    protected function setUp(): void {
        $this->maker = new BuilderMaker();
        $this->reader = new NativeValueReader(static fn (object $target): string => 'maker-id');
        $classes = new NativeEntityRegistry();
        $classes->register(BuilderEngine::class);
        $classes->register(BuilderTurboEngine::class);
        $this->builder = new NativeObjectBuilder($classes, fn (string $declaredClass, string $id): object => $id === 'maker-id' ? $this->maker : self::fail('Unknown id.'));
        BuilderCar::$constructed = 0;
    }

    private function fullCar(): BuilderCar {
        $car = new BuilderCar('VIN-1');
        $car->fuel = BuilderFuel::Diesel;
        $car->soldAt = new DateTimeImmutable('2024-05-01 08:00:00', new DateTimeZone('UTC'));
        $car->color = new BuilderColor('#ff0000');
        $car->engine = new BuilderTurboEngine();
        $car->engine->piston->diameter = 12;
        $car->spares = [new BuilderEngine(), null];
        $car->pistons = [new BuilderPiston(), new BuilderPiston()];
        $car->pistons[1]->diameter = 14;
        $car->fuels = [BuilderFuel::Petrol, BuilderFuel::Diesel];
        $car->maker = $this->maker;

        return $car;
    }

    public function testAnObjectBuiltFromWhatWasReadOffAnotherEqualsIt(): void {
        $car = $this->fullCar();

        $built = $this->builder->build(BuilderCar::class, $this->reader->read($car));

        self::assertEquals($car, $built);
        self::assertSame($this->maker, $built->maker);
        self::assertInstanceOf(BuilderTurboEngine::class, $built->engine);
    }

    public function testTheConstructorRunsOncePerClassNotOncePerObject(): void {
        $tree = $this->reader->read($this->fullCar());
        BuilderCar::$constructed = 0;

        $this->builder->build(BuilderCar::class, $tree);
        $this->builder->build(BuilderCar::class, $tree);
        $this->builder->build(BuilderCar::class, $tree);

        self::assertSame(1, BuilderCar::$constructed);
    }

    public function testNonFieldStateComesFromTheDefaultFreshPerObjectThroughClone(): void {
        $tree = $this->reader->read($this->fullCar());

        $first = $this->builder->build(BuilderCar::class, $tree);
        $second = $this->builder->build(BuilderCar::class, $tree);
        $first->log->lines[] = 'only mine';

        self::assertSame([], $second->log->lines);
    }

    public function testAReadonlyFieldIsSetFromTheTreeNotTheDefault(): void {
        self::assertSame('VIN-1', $this->builder->build(BuilderCar::class, $this->reader->read($this->fullCar()))->vin);
    }

    public function testItFillsAnUninitializedObjectWhenGivenOne(): void {
        $tree = $this->reader->read($this->fullCar());
        $ghost = (new ReflectionClass(BuilderCar::class))->newLazyGhost(function (BuilderCar $car) use ($tree): void {
            $this->builder->build(BuilderCar::class, $tree, $car);
        });

        self::assertSame('VIN-1', $ghost->vin);
        self::assertSame(BuilderFuel::Diesel, $ghost->fuel);
    }

    public function testEveryOwnedObjectIsReportedWithItsPath(): void {
        $paths = [];
        $this->builder->build(BuilderCar::class, $this->reader->read($this->fullCar()), null, static function (FieldPath $path, object $owned) use (&$paths): void {
            $paths[(string) $path] = $owned::class;
        });

        self::assertSame(['engine' => BuilderTurboEngine::class, 'spares.0' => BuilderEngine::class], $paths);
    }
}
