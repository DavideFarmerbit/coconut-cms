<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\ConvertsAnyField;
use XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data\FillsAnyField;

#[Entity(identifier: 'rep:engine')]
class RepEngine
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;
}

/** Replaced: its boost goes, its power changes meaning. */
#[Entity(identifier: 'rep:turbo')]
class RepTurbo extends RepEngine
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 5;

    #[Scalar(ScalarType::Int)]
    public int $power = 0;
}

/** A subtype of the replaced type: reparented onto the replacement. */
#[Entity(identifier: 'rep:sport')]
final class RepSport extends RepTurbo
{
    #[Scalar(ScalarType::Int)]
    public int $stripes = 2;
}

/** The replacement, a sibling: power as text, and a battery the turbo never had. */
#[Entity(identifier: 'rep:electric')]
class RepElectric extends RepEngine
{
    #[Scalar(ScalarType::String)]
    public string $power = '0 kW';

    #[Scalar(ScalarType::Int)]
    public int $battery = 50;
}

/** The sport engine, now under the replacement. */
#[Entity(identifier: 'rep:sport')]
final class RepSportOfElectric extends RepElectric
{
    #[Scalar(ScalarType::Int)]
    public int $stripes = 2;
}

/** Holds an engine of any kind, and points at one. */
#[Entity(identifier: 'rep:car')]
final class RepCar
{
    #[OwningReference]
    public ?RepEngine $engine = null;

    #[Reference]
    public ?RepEngine $favorite = null;
}

/** An int power, as kilowatts. */
final class RepPowerToKilowatts implements FieldConverter
{
    use ConvertsAnyField;

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        return Conversion::of(sprintf('%d kW', $old->value()));
    }
}

/** Every former turbo gets a full battery. */
final class RepFullBattery implements FieldInitializer
{
    use FillsAnyField;

    public function initialize(Values $values): Conversion {
        return Conversion::of(100);
    }
}

/** A turbo engine and its sport subtype, and the electric engine replacing them. */
final class MigratorReplace
{
    public const V1 = [Cms::class, RepEngine::class, RepTurbo::class, RepSport::class, RepElectric::class, RepCar::class];
    public const V2 = [Cms::class, RepEngine::class, RepElectric::class, RepSportOfElectric::class, RepCar::class];
}
