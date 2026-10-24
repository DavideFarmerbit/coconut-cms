<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

#[Entity(identifier: 'migref:car')]
final class MigRefCar
{
    #[Scalar(ScalarType::String)]
    public string $name = '';
}

#[Entity(identifier: 'migref:bike')]
final class MigRefBike
{
    #[Scalar(ScalarType::String)]
    public string $name = '';
}

/** V1: a garage holding a car. */
#[Entity(identifier: 'migref:garage')]
final class MigRefV1Garage
{
    #[Reference]
    public ?MigRefCar $vehicle = null;
}

/** V2: a garage holding a bike. */
#[Entity(identifier: 'migref:garage')]
final class MigRefV2Garage
{
    #[Reference]
    public ?MigRefBike $vehicle = null;
}

/** Keeps the id as it is: right only if it points at a bike. */
final class MigRefKeepId implements FieldConverter
{
    public function convert(Captured $old): Conversion {
        return $old->isDefault() ? Conversion::default() : Conversion::of($old->value());
    }
}

/** A Reference retargeted, every id kept: fine for an empty garage, wrong for one holding a car. */
final class MigratorReferences
{
    public const V1 = [Cms::class, MigRefCar::class, MigRefBike::class, MigRefV1Garage::class];
    public const V2 = [Cms::class, MigRefCar::class, MigRefBike::class, MigRefV2Garage::class];

    public static function steps(): MigrationSteps {
        return MigrationSteps::none()->add('garage-holds-bikes', [RetypeField::converting('migref:garage', 'vehicle', new MigRefKeepId())]);
    }
}
