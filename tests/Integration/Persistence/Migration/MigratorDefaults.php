<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Id;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

#[Entity(identifier: 'migdef:engine')]
class MigDefEngine
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;
}

#[Entity(identifier: 'migdef:turbo_engine')]
final class MigDefTurboEngine extends MigDefEngine
{
}

/** V1: a plain engine by default, 100 horsepower. */
#[Entity(identifier: 'migdef:car')]
final class MigDefV1Car
{
    #[Scalar(ScalarType::String)]
    public string $color = 'red';

    #[OwningReference]
    public ?MigDefEngine $engine;

    public function __construct() {
        $this->engine = new MigDefEngine();
    }
}

/** V2: another default color; a plain engine still, with a new default power. */
#[Entity(identifier: 'migdef:car')]
final class MigDefV2Car
{
    #[Scalar(ScalarType::String)]
    public string $color = 'blue';

    #[OwningReference]
    public ?MigDefEngine $engine;

    public function __construct() {
        $this->engine = new MigDefEngine();
        $this->engine->horsepower = 200;
    }
}

/** V3: a turbo engine by default: another class. */
#[Entity(identifier: 'migdef:car')]
final class MigDefV3Car
{
    #[Scalar(ScalarType::String)]
    public string $color = 'blue';

    #[OwningReference]
    public ?MigDefEngine $engine;

    public function __construct() {
        $this->engine = new MigDefTurboEngine();
    }
}

/** Only defaults change between versions: no step is ever needed. */
final class MigratorDefaults
{
    public const V1 = [Cms::class, MigDefEngine::class, MigDefTurboEngine::class, MigDefV1Car::class];
    public const V2 = [Cms::class, MigDefEngine::class, MigDefTurboEngine::class, MigDefV2Car::class];
    public const V3 = [Cms::class, MigDefEngine::class, MigDefTurboEngine::class, MigDefV3Car::class];
}
