<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\MigratingValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\AddType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/** V2: gains "tags", moved up from the car. */
#[Entity(identifier: 'migtest:vehicle')]
class MigV2Vehicle
{
    #[Scalar(ScalarType::String)]
    public string $name = 'vehicle';

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
    public array $tags = [];
}

/** V2: a level inserted between vehicle and car. */
#[Entity(identifier: 'migtest:motor_vehicle')]
class MigV2MotorVehicle extends MigV2Vehicle
{
    #[Scalar(ScalarType::Int)]
    public int $wheelCount = 0;
}

/** V2: its weight is now a Float, its column changing type in place; it gains a material, an addition. */
#[Entity(identifier: 'migtest:chassis')]
final class MigV2Chassis
{
    #[Scalar(ScalarType::Float)]
    public float $weight = 1000.0;

    #[Scalar(ScalarType::String)]
    public string $material = 'steel';
}

/** V2: the engine, renamed, gaining a serial. */
#[Entity(identifier: 'migtest:motor')]
final class MigV2Motor
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 300;

    #[Scalar(ScalarType::String)]
    public string $serial = '';
}

/** V2: "price" became "cost", as text; a motor vehicle now; turbo engines are gone. */
#[Entity(identifier: 'migtest:car')]
final class MigV2Car extends MigV2MotorVehicle
{
    #[Scalar(ScalarType::String)]
    public string $cost = '0 EUR';

    #[Embed]
    public MigV2Chassis $chassis;

    #[OwningReference]
    public ?MigV2Motor $engine;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: MigV2Motor::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    public function __construct() {
        $this->chassis = new MigV2Chassis();
        $this->engine = new MigV2Motor();
    }
}

/** 150 becomes "150 EUR"; a price following its default follows the new one. */
final class MigPriceToText implements FieldConverter
{
    public function convert(Captured $old): Conversion {
        return $old->isDefault() ? Conversion::default() : Conversion::of(sprintf('%d EUR', $old->value()));
    }
}

/** An Int weight as a Float; a weight following its default follows the new one. */
final class MigWeightToFloat implements FieldConverter
{
    public function convert(Captured $old): Conversion {
        return $old->isDefault() ? Conversion::default() : Conversion::of((float) $old->value());
    }
}

/** A serial from the horsepower, for a motor someone set up; a default motor keeps the default. */
final class MigSerialFromHorsepower implements FieldInitializer
{
    public function initialize(Values $values): Conversion {
        return $values->isDefault('horsepower') ? Conversion::default() : Conversion::of(sprintf('SN-%d', $values->get('horsepower')));
    }
}

/** Every existing car gets four wheels. */
final class MigFourWheels implements LevelInitializer
{
    public function initialize(MigratingValues $values): void {
        $values->set('wheelCount', Conversion::of(4));
    }
}

/** The migration test's second version of the code, and the steps from the first. */
final class MigratorV2
{
    /** Every class, Cms included. */
    public const CLASSES = [Cms::class, MigV2Vehicle::class, MigV2MotorVehicle::class, MigV2Chassis::class, MigV2Motor::class, MigV2Car::class];

    public static function steps(): MigrationSteps {
        return MigrationSteps::none()
            ->add('rename-price', [new RenameField('migtest:car', 'price', 'cost')])
            ->add('cost-as-text', [RetypeField::converting('migtest:car', 'cost', new MigPriceToText())])
            ->add('restructure', [
                new MoveField('migtest:car', 'tags', 'migtest:vehicle'),
                new RenameType('migtest:engine', 'migtest:motor'),
                new DropType('migtest:turbo_engine'),
                new AddField('migtest:motor', 'serial', new MigSerialFromHorsepower()),
                new AddType('migtest:motor_vehicle'),
                new Reparent('migtest:car', new MigFourWheels()),
                RetypeField::converting('migtest:chassis', 'weight', new MigWeightToFloat()),
            ]);
    }
}
