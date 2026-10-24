<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropType;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

#[Entity(identifier: 'migdrop:engine')]
class MigDropEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;
}

/** Only in V1: dropped by the migration. */
#[Entity(identifier: 'migdrop:turbo_engine')]
final class MigDropTurboEngine extends MigDropEngine
{
}

/** V1: its spares may be turbo engines, and its favorite may be one of them. */
#[Entity(identifier: 'migdrop:car')]
final class MigDropV1Car
{
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: MigDropEngine::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    #[Reference]
    public ?MigDropEngine $favorite = null;
}

/** V2: the same car, turbo engines gone. */
#[Entity(identifier: 'migdrop:car')]
final class MigDropV2Car
{
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: MigDropEngine::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    #[Reference]
    public ?MigDropEngine $favorite = null;
}

/** A dropped type whose entity another value of the same aggregate points at. */
final class MigratorDroppedTarget
{
    public const V1 = [Cms::class, MigDropEngine::class, MigDropTurboEngine::class, MigDropV1Car::class];
    public const V2 = [Cms::class, MigDropEngine::class, MigDropV2Car::class];

    public static function steps(): MigrationSteps {
        return MigrationSteps::none()->add('no-turbos', [new DropType('migdrop:turbo_engine')]);
    }
}
